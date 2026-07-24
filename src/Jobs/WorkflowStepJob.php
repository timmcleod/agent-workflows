<?php

namespace TimMcLeod\AgentWorkflows\Jobs;

use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Log;
use Throwable;
use TimMcLeod\AgentWorkflows\ConditionStepDefinition;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\EvaluateStepDefinition;
use TimMcLeod\AgentWorkflows\Events\WorkflowFailed;
use TimMcLeod\AgentWorkflows\Exceptions\DefinitionDriftException;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Models\WorkflowStep;
use TimMcLeod\AgentWorkflows\ParallelStepDefinition;
use TimMcLeod\AgentWorkflows\Runtime\BranchRunner;
use TimMcLeod\AgentWorkflows\Runtime\ParallelStepCompleter;
use TimMcLeod\AgentWorkflows\Runtime\Progression;
use TimMcLeod\AgentWorkflows\Runtime\StateMerger;
use TimMcLeod\AgentWorkflows\Runtime\StepExecutors;
use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;
use TimMcLeod\AgentWorkflows\WorkflowState;

/**
 * Executes exactly one workflow step. The payload carries only identifiers —
 * state is loaded fresh from the run's checkpoint, so a retried job never
 * sees stale state.
 */
class WorkflowStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Step-level retries are explicit (run->retry() from the checkpoint), so
     * the queue-level default is a single attempt.
     */
    public int $tries = 1;

    public function __construct(
        public string $runId,
        public string $stepId,
    ) {
        $this->onConnection(config('agent-workflows.queue.connection'));
        $this->onQueue(config('agent-workflows.queue.queue'));
    }

    public function handle(WorkflowRegistry $registry): void
    {
        $run = WorkflowRun::query()->find($this->runId);

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        $definition = $registry->get($run->name);

        $this->guardAgainstDrift($run, $definition);

        $step = $definition->step($this->stepId);
        $state = $run->workflowState();
        $stepRow = $this->beginStep($run, $step, $state);

        match (true) {
            $step instanceof ConditionStepDefinition => $this->runCondition($run, $definition, $step, $state, $stepRow),
            $step instanceof ParallelStepDefinition => $this->runParallel($run, $definition, $step, $state, $stepRow),
            $step instanceof EvaluateStepDefinition => $this->runEvaluate($run, $definition, $step, $state, $stepRow),
            default => $this->runSimple($run, $definition, $step, $state, $stepRow),
        };
    }

    public function failed(?Throwable $exception): void
    {
        $run = WorkflowRun::query()->find($this->runId);

        // On the sync queue an inner step's exception also unwinds through
        // the jobs that dispatched it; only the first failure counts.
        if ($run === null || $run->status === RunStatus::Failed) {
            return;
        }

        $run->update([
            'status' => RunStatus::Failed,
            'failed_step' => $this->stepId,
            'failure_reason' => $exception?->getMessage(),
        ]);

        event(new WorkflowFailed($run, $exception));
    }

    protected function beginStep(WorkflowRun $run, StepDefinition $step, WorkflowState $state): WorkflowStep
    {
        $stepRow = $run->steps()->create([
            'step_id' => $step->id,
            'type' => $step->type,
            'status' => StepStatus::Running,
            'attempt' => $run->steps()->where('step_id', $step->id)->count() + 1,
            'input_state_hash' => $state->hash(),
            'started_at' => now(),
        ]);

        $run->update([
            'status' => RunStatus::Running,
            'current_step' => $step->id,
            'started_at' => $run->started_at ?? now(),
        ]);

        return $stepRow;
    }

    protected function runSimple(
        WorkflowRun $run,
        WorkflowDefinition $definition,
        StepDefinition $step,
        WorkflowState $state,
        WorkflowStep $stepRow,
    ): void {
        $result = $this->attempt($stepRow, fn () => app(StepExecutors::class)->for($step)->execute($step, $state));

        app(Progression::class)->complete($run, $definition, $step, $stepRow, $result->state, $result->usage);
    }

    protected function runCondition(
        WorkflowRun $run,
        WorkflowDefinition $definition,
        ConditionStepDefinition $step,
        WorkflowState $state,
        WorkflowStep $stepRow,
    ): void {
        $chosen = $this->attempt($stepRow, fn () => ($step->condition)($state) ? $step->whenTrue : $step->whenFalse);

        $state->set('steps.'.$step->id.'.branch', $chosen !== null ? $chosen->id : 'skipped');

        // With no else-branch, a false condition skips ahead to the next
        // sequential step; otherwise the chosen branch runs next.
        app(Progression::class)->complete(
            $run, $definition, $step, $stepRow, $state, nextOverride: $chosen,
        );
    }

    protected function runEvaluate(
        WorkflowRun $run,
        WorkflowDefinition $definition,
        EvaluateStepDefinition $step,
        WorkflowState $state,
        WorkflowStep $stepRow,
    ): void {
        $iteration = (int) $state->get('steps.'.$step->id.'.iteration', 0) + 1;

        $result = $this->attempt($stepRow, fn () => app(StepExecutors::class)->for($step->body)->execute($step->body, $state));

        $satisfied = ($step->until)($result->state);

        $state = $result->state
            ->set('steps.'.$step->id.'.iteration', $iteration)
            ->set('steps.'.$step->id.'.satisfied', $satisfied);

        app(Progression::class)->complete(
            $run, $definition, $step, $stepRow, $state, $result->usage,
            // Loop back into this same step until satisfied or out of budget.
            nextOverride: $satisfied || $iteration >= $step->maxIterations ? null : $step,
        );
    }

    protected function runParallel(
        WorkflowRun $run,
        WorkflowDefinition $definition,
        ParallelStepDefinition $step,
        WorkflowState $state,
        WorkflowStep $stepRow,
    ): void {
        $connection = config('agent-workflows.queue.connection') ?? config('queue.default');

        // On the sync queue driver a batch would execute inline inside the
        // batch repository's transaction, rolling back branch checkpoints on
        // failure — and "durable" means nothing there anyway. Run in-process.
        if ($step->mode === 'sync' || config("queue.connections.{$connection}.driver") === 'sync') {
            $this->runParallelSync($run, $definition, $step, $state, $stepRow);

            return;
        }

        [$runId, $stepId, $stepRowId] = [$run->id, $step->id, $stepRow->id];

        Bus::batch(array_map(
            fn (StepDefinition $branch) => new ParallelBranchJob($runId, $stepId, $branch->id),
            $step->branches,
        ))
            ->then(function () use ($runId, $stepId, $stepRowId) {
                app(ParallelStepCompleter::class)->complete($runId, $stepId, $stepRowId);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($runId, $stepId, $stepRowId) {
                app(ParallelStepCompleter::class)->fail($runId, $stepId, $stepRowId, $e);
            })
            ->name("agent-workflow:{$run->name}:{$stepId}")
            ->onConnection($connection)
            ->dispatch();

        // The parallel step row stays "running" until the batch callbacks
        // merge the branch states (or fail the run).
    }

    protected function runParallelSync(
        WorkflowRun $run,
        WorkflowDefinition $definition,
        ParallelStepDefinition $step,
        WorkflowState $state,
        WorkflowStep $stepRow,
    ): void {
        [$runId, $stepId] = [$run->id, $step->id];

        $results = $this->attempt($stepRow, fn () => Concurrency::run(array_map(
            fn (StepDefinition $branch) => fn (): array => app(BranchRunner::class)->run($runId, $stepId, $branch->id),
            $step->branches,
        )));

        $branchStates = array_combine(
            array_map(fn (StepDefinition $branch) => $branch->id, $step->branches),
            $results,
        );

        $merged = $this->attempt($stepRow, fn () => app(StateMerger::class)->merge($step, $state->all(), $branchStates));

        app(Progression::class)->complete($run, $definition, $step, $stepRow, $merged);
    }

    /**
     * Run a unit of step work, recording any failure on the audit row before
     * letting the exception fail the job (and, via failed(), the run).
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    protected function attempt(WorkflowStep $stepRow, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            $stepRow->update([
                'status' => StepStatus::Failed,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    protected function guardAgainstDrift(WorkflowRun $run, WorkflowDefinition $definition): void
    {
        if ($run->version === $definition->hash()) {
            return;
        }

        if (config('agent-workflows.definition_drift') === 'strict') {
            throw new DefinitionDriftException(
                "Workflow [{$run->name}] definition has changed since run [{$run->id}] started. ".
                'Set agent-workflows.definition_drift to "loose" to resume best-effort by step name.'
            );
        }

        if (! $definition->hasStep($this->stepId)) {
            throw new DefinitionDriftException(
                "Workflow [{$run->name}] definition has changed and step [{$this->stepId}] no longer exists."
            );
        }

        Log::warning("Agent workflow run [{$run->id}] is resuming on a drifted definition of [{$run->name}].");
    }
}
