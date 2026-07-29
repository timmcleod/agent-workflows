<?php

namespace TimMcLeod\AgentWorkflows\Jobs;

use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use TimMcLeod\AgentWorkflows\AwaitEventStepDefinition;
use TimMcLeod\AgentWorkflows\AwaitHumanStepDefinition;
use TimMcLeod\AgentWorkflows\ConditionStepDefinition;
use TimMcLeod\AgentWorkflows\Enums\InterruptType;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\EvaluateStepDefinition;
use TimMcLeod\AgentWorkflows\Events\WorkflowFailed;
use TimMcLeod\AgentWorkflows\Interrupts\PendingInterrupt;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Models\WorkflowStep;
use TimMcLeod\AgentWorkflows\ParallelStepDefinition;
use TimMcLeod\AgentWorkflows\Runtime\BranchRunner;
use TimMcLeod\AgentWorkflows\Runtime\DriftGuard;
use TimMcLeod\AgentWorkflows\Runtime\Interrupter;
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

        $step = $definition->findStep($this->stepId);

        $claim = $this->claim($step);

        if ($claim === null) {
            return; // duplicate or stale delivery — see claim()
        }

        [$run, $state, $stepRow] = $claim;

        match (true) {
            $step instanceof ConditionStepDefinition => $this->runCondition($run, $definition, $step, $state, $stepRow),
            $step instanceof ParallelStepDefinition => $this->runParallel($run, $definition, $step, $state, $stepRow),
            $step instanceof EvaluateStepDefinition => $this->runEvaluate($run, $definition, $step, $state, $stepRow),
            $step instanceof AwaitHumanStepDefinition,
            $step instanceof AwaitEventStepDefinition => $this->runAwait($run, $definition, $step, $state, $stepRow),
            default => $this->runSimple($run, $definition, $step, $state, $stepRow),
        };
    }

    public function failed(?Throwable $exception): void
    {
        // A MaxAttemptsExceededException with a live attempt on the books
        // is not a failure — it's the queue redelivering a job whose first
        // attempt is still executing (retry_after shorter than the step).
        // Failing here would poison the healthy run and discard the
        // original attempt's paid-for result when it commits; leave
        // staleness adjudication to the sweep, which knows the cutoff.
        if ($exception instanceof MaxAttemptsExceededException && $this->hasRecentInFlightAttempt()) {
            Log::warning(
                "Agent workflow step [{$this->stepId}] of run [{$this->runId}] was redelivered while still executing; ".
                'ignoring the redelivery. Raise the queue\'s retry_after above your slowest step to avoid this.'
            );

            return;
        }

        // Conditional transition: only the run's cursor step may fail an
        // active run. This no-ops both duplicate failure reports and the
        // sync-queue case where an inner step's exception unwinds through
        // the jobs that dispatched it.
        $failed = WorkflowRun::query()
            ->whereKey($this->runId)
            ->where('current_step', $this->stepId)
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
            ->update([
                'status' => RunStatus::Failed->value,
                'failed_step' => $this->stepId,
                'failure_reason' => $exception?->getMessage(),
                'updated_at' => now(),
            ]);

        if ($failed === 0) {
            return;
        }

        $run = WorkflowRun::query()->find($this->runId);

        if ($run !== null) {
            event(new WorkflowFailed($run, $exception));
        }
    }

    /**
     * Whether this step has a running audit row young enough (per the
     * sweep's staleness cutoff) to plausibly belong to a worker that is
     * genuinely still executing.
     */
    protected function hasRecentInFlightAttempt(): bool
    {
        $staleAfter = (int) config('agent-workflows.sweep.stale_after', 600);

        $run = WorkflowRun::query()->find($this->runId);

        return $run !== null && $run->steps()
            ->where('step_id', $this->stepId)
            ->where('status', StepStatus::Running->value)
            ->where('started_at', '>=', now()->subSeconds($staleAfter))
            ->exists();
    }

    /**
     * Atomically claim the step for execution. Returns null when this
     * delivery is a duplicate or stale: the cursor has moved on, the run is
     * parked/terminal, another worker is mid-flight on the same step, or a
     * concurrent claim won the audit-row insert (unique constraint).
     *
     * @return array{0: WorkflowRun, 1: WorkflowState, 2: WorkflowStep}|null
     */
    protected function claim(StepDefinition $step): ?array
    {
        try {
            return DB::transaction(function () use ($step) {
                $run = WorkflowRun::query()->lockForUpdate()->find($this->runId);

                $claimable = $run !== null
                    && in_array($run->status, [RunStatus::Pending, RunStatus::Running], true)
                    && $run->current_step === $this->stepId
                    && ! $run->steps()
                        ->where('step_id', $this->stepId)
                        ->where('status', StepStatus::Running->value)
                        ->exists();

                if (! $claimable) {
                    Log::info("Agent workflow step [{$this->stepId}] of run [{$this->runId}] skipped a duplicate or stale delivery.");

                    return null;
                }

                $state = $run->workflowState();

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
                    'started_at' => $run->started_at ?? now(),
                ]);

                return [$run, $state, $stepRow];
            });
        } catch (UniqueConstraintViolationException) {
            Log::info("Agent workflow step [{$this->stepId}] of run [{$this->runId}] lost a concurrent claim; skipping.");

            return null;
        }
    }

    protected function runSimple(
        WorkflowRun $run,
        WorkflowDefinition $definition,
        StepDefinition $step,
        WorkflowState $state,
        WorkflowStep $stepRow,
    ): void {
        $result = $this->attempt($stepRow, fn () => app(StepExecutors::class)->for($step)->execute($step, $state));

        // An agent step may ask to park the run (e.g. the SDK paused on tool
        // approvals) instead of completing.
        if ($result->interrupt !== null) {
            app(Interrupter::class)->interrupt($run, $step, $stepRow, $result->state, $result->interrupt);

            return;
        }

        app(Progression::class)->complete($run, $definition, $step, $stepRow, $result->state, $result->usage);
    }

    protected function runAwait(
        WorkflowRun $run,
        WorkflowDefinition $definition,
        AwaitHumanStepDefinition|AwaitEventStepDefinition $step,
        WorkflowState $state,
        WorkflowStep $stepRow,
    ): void {
        $open = $run->interrupts()->where('step_id', $step->id)->whereNull('resolved_at')->latest('id')->first();
        $resolved = $run->interrupts()->where('step_id', $step->id)->whereNotNull('resolved_at')->latest('id')->first();

        // Dispatched by resume()/deliverEvent(): the response is already
        // merged into state — complete the await step and move on.
        if ($open === null && $resolved !== null) {
            app(Progression::class)->complete($run, $definition, $step, $stepRow, $state);

            return;
        }

        $pending = $step instanceof AwaitHumanStepDefinition
            ? new PendingInterrupt(
                InterruptType::Human,
                $step->reason,
                $step->schema,
                timeoutAt: $step->timeout !== null ? now()->addSeconds($step->timeout) : null,
            )
            : new PendingInterrupt(InterruptType::Event, "Waiting for event [{$step->event}].", $step->schema, context: ['event' => $step->event]);

        app(Interrupter::class)->interrupt($run, $step, $stepRow, $state, $pending);
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

        // The body paused mid-turn (agent tool approvals): park the run at
        // the evaluate step without consuming an iteration. On resume the
        // step re-runs, replays the decisions, and the loop continues.
        if ($result->interrupt !== null) {
            app(Interrupter::class)->interrupt($run, $step, $stepRow, $result->state, $result->interrupt);

            return;
        }

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

        $merged = $this->attempt($stepRow, fn () => app(StateMerger::class)->merge($step, $state->all(), $branchStates, $definition->stateClass));

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
        app(DriftGuard::class)->check($run, $definition, $this->stepId);
    }
}
