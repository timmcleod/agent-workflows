<?php

namespace TimMcLeod\AgentWorkflows\Runtime;

use Throwable;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Events\StepCompleted;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\ParallelStepDefinition;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

/**
 * Executes one branch of a parallel step against the run's state snapshot
 * and records it on the audit log. The run's own state is not touched —
 * branch results are merged only when every branch has finished.
 */
class BranchRunner
{
    public function __construct(
        protected WorkflowRegistry $registry,
        protected StepExecutors $executors,
    ) {}

    /**
     * @return array<string, mixed> the branch's resulting state
     */
    public function run(string $runId, string $parallelStepId, string $branchId): array
    {
        $run = WorkflowRun::query()->find($runId);

        if ($run === null) {
            throw new WorkflowException("Workflow run [{$runId}] no longer exists.");
        }

        $definition = $this->registry->get($run->name);
        $parallel = $definition->step($parallelStepId);

        if (! $parallel instanceof ParallelStepDefinition) {
            throw new WorkflowException("Step [{$parallelStepId}] is not a parallel step.");
        }

        $branch = $parallel->branch($branchId);
        $state = $run->workflowState();

        $stepRow = $run->steps()->create([
            'step_id' => $branch->id,
            'type' => $branch->type,
            'status' => StepStatus::Running,
            'attempt' => $run->steps()->where('step_id', $branch->id)->count() + 1,
            'input_state_hash' => $state->hash(),
            'started_at' => now(),
        ]);

        try {
            $result = $this->executors->for($branch)->execute($branch, $state);
        } catch (Throwable $e) {
            $stepRow->update([
                'status' => StepStatus::Failed,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }

        $stepRow->update([
            'status' => StepStatus::Completed,
            'output_state' => $result->state->all(),
            'usage' => $result->usage,
            'finished_at' => now(),
        ]);

        event(new StepCompleted($run, $stepRow));

        return $result->state->all();
    }
}
