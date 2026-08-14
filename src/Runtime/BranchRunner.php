<?php

namespace TimMcLeod\AgentWorkflows\Runtime;

use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Events\StepCompleted;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Models\WorkflowStep;
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
        protected DriftGuard $driftGuard,
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

        // A cancelled, completed, or failed run must not accrue new agent
        // turns (LLM spend, tool side effects) from branches still sitting
        // on the queue.
        if ($run->status->isTerminal() || $run->status === RunStatus::Failed) {
            throw new WorkflowException(
                "Workflow run [{$runId}] is [{$run->status->value}]; branch [{$branchId}] will not execute."
            );
        }

        $definition = $this->registry->get($run->name);

        $this->driftGuard->check($run, $definition, $branchId);

        $parallel = $definition->findStep($parallelStepId);

        if (! $parallel instanceof ParallelStepDefinition) {
            throw new WorkflowException("Step [{$parallelStepId}] is not a parallel step.");
        }

        $branch = $parallel->branch($branchId);
        $state = $run->workflowState();

        // Duplicate delivery of a branch job must not double-execute. It
        // also must not "succeed" quietly: in sync mode the return value IS
        // the branch's contribution to the merge, so returning the input
        // snapshot here would complete the run with this branch's work
        // silently missing. Throwing fails the fan-out loudly instead —
        // retry() supersedes stale rows, so the retried fan-out claims
        // cleanly.
        $inFlight = $run->steps()
            ->where('step_id', $branch->id)
            ->where('status', StepStatus::Running->value)
            ->exists();

        if ($inFlight) {
            throw new WorkflowException(
                "Branch [{$branch->id}] of run [{$runId}] already has an attempt in flight — ".
                'a stale attempt from a crashed worker, or a concurrent duplicate delivery.'
            );
        }

        try {
            $stepRow = $run->steps()->create([
                'step_id' => $branch->id,
                'type' => $branch->type,
                'status' => StepStatus::Running,
                'attempt' => $run->steps()->where('step_id', $branch->id)->count() + 1,
                'input_state_hash' => $state->hash(),
                'started_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new WorkflowException(
                "Branch [{$branch->id}] of run [{$runId}] lost a concurrent claim to another worker."
            );
        }

        try {
            $result = $this->executors->for($branch)->execute($branch, $state);

            // A branch cannot park the run: fan-out state lives in N
            // concurrent jobs and the engine has no multi-interrupt story
            // yet. Completing the branch mid-turn would silently drop the
            // approval, so fail loudly instead (the batch fails the run at
            // the parallel step).
            if ($result->interrupt !== null) {
                throw new WorkflowException(
                    "Branch [{$branch->id}] of parallel step [{$parallelStepId}] paused on tool approvals. ".
                    'Interrupts are not supported inside parallel branches — move the approval-gated agent '
                    .'to a sequential step before or after the fan-out.'
                );
            }
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
            ...WorkflowStep::callsAudit($result->calls),
            'finished_at' => now(),
        ]);

        event(new StepCompleted($run, $stepRow));

        return $result->state->all();
    }
}
