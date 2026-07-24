<?php

namespace TimMcLeod\AgentWorkflows\Runtime;

use Throwable;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Events\WorkflowFailed;
use TimMcLeod\AgentWorkflows\Exceptions\StateMergeConflictException;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Models\WorkflowStep;
use TimMcLeod\AgentWorkflows\ParallelStepDefinition;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

/**
 * Batch-callback target for durable ("batch" mode) parallel steps: merges
 * the branch states once every branch job succeeded, or fails the run at
 * the parallel step when the batch fails.
 */
class ParallelStepCompleter
{
    public function __construct(
        protected WorkflowRegistry $registry,
        protected StateMerger $merger,
        protected Progression $progression,
    ) {}

    public function complete(string $runId, string $stepId, int $stepRowId): void
    {
        $run = WorkflowRun::query()->find($runId);
        $stepRow = WorkflowStep::query()->find($stepRowId);

        if ($run === null || $stepRow === null || $run->status->isTerminal()) {
            return;
        }

        $definition = $this->registry->get($run->name);
        $step = $definition->findStep($stepId);

        if (! $step instanceof ParallelStepDefinition) {
            throw new WorkflowException("Step [{$stepId}] is not a parallel step.");
        }

        $branchStates = [];

        foreach ($step->branches as $branch) {
            $row = $run->steps()
                ->where('step_id', $branch->id)
                ->where('status', StepStatus::Completed->value)
                ->orderByDesc('id')
                ->first();

            if ($row === null) {
                $this->fail($runId, $stepId, $stepRowId, new WorkflowException(
                    "Parallel step [{$stepId}] finished its batch but branch [{$branch->id}] has no completed result."
                ));

                return;
            }

            $branchStates[$branch->id] = $row->output_state ?? [];
        }

        try {
            $merged = $this->merger->merge($step, $run->state ?? [], $branchStates);
        } catch (StateMergeConflictException $e) {
            $this->fail($runId, $stepId, $stepRowId, $e);

            return;
        }

        $this->progression->complete($run, $definition, $step, $stepRow, $merged);
    }

    public function fail(string $runId, string $stepId, int $stepRowId, Throwable $exception): void
    {
        $run = WorkflowRun::query()->find($runId);

        if ($run === null || $run->status === RunStatus::Failed) {
            return;
        }

        WorkflowStep::query()->find($stepRowId)?->update([
            'status' => StepStatus::Failed,
            'error' => $exception->getMessage(),
            'finished_at' => now(),
        ]);

        $run->update([
            'status' => RunStatus::Failed,
            'failed_step' => $stepId,
            'failure_reason' => $exception->getMessage(),
        ]);

        event(new WorkflowFailed($run, $exception));
    }
}
