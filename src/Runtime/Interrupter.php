<?php

namespace TimMcLeod\AgentWorkflows\Runtime;

use Illuminate\Support\Facades\DB;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Events\WorkflowInterrupted;
use TimMcLeod\AgentWorkflows\Interrupts\PendingInterrupt;
use TimMcLeod\AgentWorkflows\Models\WorkflowInterrupt;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Models\WorkflowStep;
use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

/**
 * Parks a run: persists the interrupt (reason, response schema, context),
 * checkpoints state, and flips the run to its awaiting status. Nothing is
 * dispatched — the run sits until resume() / deliverEvent() wakes it.
 */
class Interrupter
{
    public function interrupt(
        WorkflowRun $run,
        StepDefinition $step,
        WorkflowStep $stepRow,
        WorkflowState $state,
        PendingInterrupt $pending,
    ): WorkflowInterrupt {
        $interrupt = DB::transaction(function () use ($run, $step, $stepRow, $state, $pending) {
            // A duplicate delivery of the step job must not stack a second
            // open interrupt on the same step.
            $interrupt = $run->interrupts()
                ->where('step_id', $step->id)
                ->whereNull('resolved_at')
                ->latest('id')
                ->first();

            $interrupt ??= $run->interrupts()->create([
                'step_id' => $step->id,
                'type' => $pending->type,
                'reason' => $pending->reason,
                'response_schema' => $pending->schema,
                'context' => $pending->context,
            ]);

            $run->update([
                'state' => $state->all(),
                'status' => $pending->type->runStatus(),
                'current_step' => $step->id,
            ]);

            $stepRow->update([
                'status' => StepStatus::Interrupted,
                'output_state' => $state->all(),
                'finished_at' => now(),
            ]);

            return $interrupt;
        });

        event(new WorkflowInterrupted($run, $interrupt));

        return $interrupt;
    }
}
