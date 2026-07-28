<?php

namespace TimMcLeod\AgentWorkflows\Runtime;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
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
    ): ?WorkflowInterrupt {
        $interrupt = DB::transaction(function () use ($run, $step, $stepRow, $state, $pending) {
            // Conditional park: only a run still executing this step may be
            // interrupted — a concurrent transition (cancel, fail) wins.
            $parked = WorkflowRun::query()
                ->whereKey($run->id)
                ->where('current_step', $step->id)
                ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
                ->update([
                    'state' => json_encode($state->all(), JSON_THROW_ON_ERROR),
                    'status' => $pending->type->runStatus()->value,
                    'updated_at' => now(),
                ]);

            if ($parked === 0) {
                $stepRow->update([
                    'status' => StepStatus::Failed,
                    'error' => 'Run state changed during execution; interrupt discarded.',
                    'finished_at' => now(),
                ]);

                return null;
            }

            // A duplicate delivery of the step job must not stack a second
            // open interrupt on the same step.
            $interrupt = $run->interrupts()
                ->where('step_id', $step->id)
                ->whereNull('resolved_at')
                ->latest('id')
                ->first();

            if ($interrupt !== null) {
                // Re-parking on an existing wait (a retry after a timeout
                // failure, a duplicate delivery) re-arms its deadline.
                $interrupt->update(['timeout_at' => $pending->timeoutAt]);
            } else {
                $interrupt = $run->interrupts()->create([
                    'step_id' => $step->id,
                    'type' => $pending->type,
                    'reason' => $pending->reason,
                    'response_schema' => $pending->schema,
                    'context' => $pending->context,
                    'timeout_at' => $pending->timeoutAt,
                ]);
            }

            $stepRow->update([
                'status' => StepStatus::Interrupted,
                'output_state' => $state->all(),
                'finished_at' => now(),
            ]);

            return $interrupt;
        });

        if ($interrupt === null) {
            Log::warning("Agent workflow run [{$run->id}] changed while step [{$step->id}] was executing; its interrupt was discarded.");

            return null;
        }

        event(new WorkflowInterrupted($run->refresh(), $interrupt));

        return $interrupt;
    }
}
