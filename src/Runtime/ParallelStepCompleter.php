<?php

namespace TimMcLeod\AgentWorkflows\Runtime;

use Throwable;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Events\WorkflowFailed;
use TimMcLeod\AgentWorkflows\Exceptions\DefinitionDriftException;
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
        protected DriftGuard $driftGuard,
    ) {}

    public function complete(string $runId, string $stepId, int $stepRowId): void
    {
        $run = WorkflowRun::query()->find($runId);
        $stepRow = WorkflowStep::query()->find($stepRowId);

        if ($run === null || $stepRow === null || $run->status->isTerminal()) {
            return;
        }

        $definition = $this->registry->get($run->name);

        try {
            $this->driftGuard->check($run, $definition, $stepId);
        } catch (DefinitionDriftException $e) {
            $this->fail($runId, $stepId, $stepRowId, $e);

            return;
        }

        $step = $definition->findStep($stepId);

        if (! $step instanceof ParallelStepDefinition) {
            throw new WorkflowException("Step [{$stepId}] is not a parallel step.");
        }

        $branchStates = [];

        foreach ($step->branches as $branch) {
            // Generation fence: the parallel step's audit row is created
            // before its branch rows, so only rows with a higher id belong
            // to THIS fan-out. Without it, a retried fan-out could merge a
            // stale completed result from a previous generation.
            $row = $run->steps()
                ->where('step_id', $branch->id)
                ->where('status', StepStatus::Completed->value)
                ->where('id', '>', $stepRowId)
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
            $merged = $this->merger->merge($step, $run->state ?? [], $branchStates, $definition->stateClass);
        } catch (StateMergeConflictException $e) {
            $this->fail($runId, $stepId, $stepRowId, $e);

            return;
        }

        $this->progression->complete($run, $definition, $step, $stepRow, $merged);
    }

    public function fail(string $runId, string $stepId, int $stepRowId, Throwable $exception): void
    {
        WorkflowStep::query()
            ->whereKey($stepRowId)
            ->where('status', StepStatus::Running->value)
            ->update([
                'status' => StepStatus::Failed->value,
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

        // Conditional transition: only fail a run still executing this
        // parallel step — duplicate batch callbacks and races with other
        // transitions no-op.
        $update = [
            'status' => RunStatus::Failed->value,
            'failed_step' => $stepId,
            'failure_reason' => $exception->getMessage(),
            'updated_at' => now(),
        ];

        if (WorkflowRun::schemaHasKeyColumns()) {
            $update['active_key'] = null;
        }

        $failed = WorkflowRun::query()
            ->whereKey($runId)
            ->where('current_step', $stepId)
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
            ->update($update);

        if ($failed === 0) {
            return;
        }

        $run = WorkflowRun::query()->find($runId);

        if ($run !== null) {
            event(new WorkflowFailed($run, $exception));

            app(GroupSettler::class)->settle($run->group_key);
        }
    }
}
