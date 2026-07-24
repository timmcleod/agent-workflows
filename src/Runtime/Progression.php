<?php

namespace TimMcLeod\AgentWorkflows\Runtime;

use Illuminate\Support\Facades\DB;
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

        DB::transaction(function () use ($run, $stepRow, $state, $usage, $step, $next) {
            $run->update([
                'state' => $state->all(),
                'current_step' => $next !== null ? $next->id : $step->id,
                'status' => $next !== null ? RunStatus::Running : RunStatus::Completed,
                'finished_at' => $next !== null ? null : now(),
            ]);

            $stepRow->update([
                'status' => StepStatus::Completed,
                'output_state' => $state->all(),
                'usage' => $usage,
                'finished_at' => now(),
            ]);
        });

        event(new StepCompleted($run, $stepRow));

        if ($next !== null) {
            WorkflowStepJob::dispatch($run->id, $next->id)->afterCommit();
        } else {
            event(new WorkflowCompleted($run));
        }
    }
}
