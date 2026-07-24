<?php

namespace TimMcLeod\AgentWorkflows\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Exceptions\DefinitionDriftException;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\Steps\AgentStepExecutor;
use TimMcLeod\AgentWorkflows\Steps\CallbackStepExecutor;
use TimMcLeod\AgentWorkflows\Steps\StepExecutor;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

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

        try {
            $result = $this->executorFor($step)->execute($step, $state);
        } catch (Throwable $e) {
            $stepRow->update([
                'status' => StepStatus::Failed,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }

        $next = $definition->after($step->id);

        // The checkpoint: the new state and the step's completion are
        // committed atomically before the next step is dispatched.
        DB::transaction(function () use ($run, $stepRow, $result, $step, $next) {
            $run->update([
                'state' => $result->state->all(),
                'current_step' => $next !== null ? $next->id : $step->id,
                'status' => $next !== null ? RunStatus::Running : RunStatus::Completed,
                'finished_at' => $next !== null ? null : now(),
            ]);

            $stepRow->update([
                'status' => StepStatus::Completed,
                'output_state' => $result->state->all(),
                'usage' => $result->usage,
                'finished_at' => now(),
            ]);
        });

        if ($next !== null) {
            static::dispatch($run->id, $next->id)->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = WorkflowRun::query()->find($this->runId);

        // Only the run's cursor step may fail the run — on the sync queue,
        // an inner step's exception also unwinds through the jobs that
        // dispatched it, and those must not overwrite the real failure.
        if ($run === null || $run->current_step !== $this->stepId) {
            return;
        }

        $run->update([
            'status' => RunStatus::Failed,
            'failed_step' => $this->stepId,
            'failure_reason' => $exception?->getMessage(),
        ]);
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

    protected function executorFor(StepDefinition $step): StepExecutor
    {
        return match ($step->type) {
            StepType::Agent => app(AgentStepExecutor::class),
            StepType::Callback => app(CallbackStepExecutor::class),
            default => throw new WorkflowException("Step type [{$step->type->value}] is not supported yet."),
        };
    }
}
