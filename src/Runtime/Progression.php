<?php

namespace TimMcLeod\AgentWorkflows\Runtime;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Events\StepCompleted;
use TimMcLeod\AgentWorkflows\Events\WorkflowCompleted;
use TimMcLeod\AgentWorkflows\Jobs\WorkflowStepJob;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Models\WorkflowStep;
use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

/**
 * Commits a step's checkpoint and moves the run forward: the new state and
 * the step's completion are written in one transaction, then the successor
 * is dispatched (or the run is completed).
 */
class Progression
{
    /**
     * @param  array<string, int>|null  $usage
     * @param  StepDefinition|null  $nextOverride  explicit successor (condition
     *                                             branches, evaluate re-dispatch)
     */
    public function complete(
        WorkflowRun $run,
        WorkflowDefinition $definition,
        StepDefinition $step,
        WorkflowStep $stepRow,
        WorkflowState $state,
        ?array $usage = null,
        ?StepDefinition $nextOverride = null,
    ): void {
        $next = $nextOverride ?? $definition->after($step->id);

        $advanced = DB::transaction(function () use ($run, $stepRow, $state, $usage, $step, $next) {
            // Conditional advance: commit only if the run is still executing
            // this step. A concurrent transition (another worker advanced,
            // the run failed or was resumed elsewhere) makes this a no-op —
            // exactly one completion may move the cursor.
            $advanced = WorkflowRun::query()
                ->whereKey($run->id)
                ->where('current_step', $step->id)
                ->where('status', RunStatus::Running->value)
                ->update([
                    'state' => json_encode($state->all(), JSON_THROW_ON_ERROR),
                    'current_step' => $next !== null ? $next->id : $step->id,
                    'status' => $next !== null ? RunStatus::Running->value : RunStatus::Completed->value,
                    'finished_at' => $next !== null ? null : now(),
                    'updated_at' => now(),
                ]);

            if ($advanced === 0) {
                $stepRow->update([
                    'status' => StepStatus::Failed,
                    'error' => 'Run state changed during execution; result discarded.',
                    'finished_at' => now(),
                ]);

                return false;
            }

            $stepRow->update([
                'status' => StepStatus::Completed,
                'output_state' => $this->auditSnapshot($step, $state),
                'usage' => $usage,
                'finished_at' => now(),
            ]);

            return true;
        });

        if (! $advanced) {
            Log::warning("Agent workflow run [{$run->id}] changed while step [{$step->id}] was executing; its result was discarded.");

            return;
        }

        $run->refresh();

        event(new StepCompleted($run, $stepRow));

        if ($next !== null) {
            WorkflowStepJob::dispatch($run->id, $next->id)->afterCommit();
        } else {
            event(new WorkflowCompleted($run));
        }
    }

    /**
     * What the step's audit row records as output_state. Sequential rows
     * are pure audit data (execution always reloads from the run's
     * checkpoint), and full snapshots grow O(n²) over a run's life —
     * every row repeating all prior agent output. "minimal" stores just
     * the step's own checkpoint subtree. Parallel BRANCH rows are
     * unaffected either way: the merge consumes them (see BranchRunner).
     *
     * @return array<string, mixed>|null
     */
    protected function auditSnapshot(StepDefinition $step, WorkflowState $state): ?array
    {
        if (config('agent-workflows.audit.step_output', 'full') === 'minimal') {
            $own = $state->get('steps.'.$step->id);

            return is_array($own) ? ['steps' => [$step->id => $own]] : null;
        }

        return $state->all();
    }
}
