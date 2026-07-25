<?php

namespace TimMcLeod\AgentWorkflows\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Events\WorkflowFailed;
use TimMcLeod\AgentWorkflows\Jobs\WorkflowStepJob;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

/**
 * Recovers runs stranded by the failure modes the engine cannot see:
 * a worker hard-killed mid-step (SIGKILL, OOM, deploy) leaves the run
 * "running" forever, and a process dying between checkpoint commit and
 * queue push leaves a run whose next step was never dispatched.
 *
 * Idempotent by construction: re-dispatching is safe because step claims
 * reject duplicates, and the conditional advance lets at most one attempt
 * move the cursor.
 */
#[AsCommand(name: 'agent-workflows:sweep')]
class SweepCommand extends Command
{
    protected $signature = 'agent-workflows:sweep';

    protected $description = 'Recover runs stranded by dead workers or lost dispatches';

    public function handle(): int
    {
        $staleAfter = (int) config('agent-workflows.sweep.stale_after', 600);
        $action = (string) config('agent-workflows.sweep.action', 'redispatch');
        $cutoff = now()->subSeconds($staleAfter);

        $stranded = WorkflowRun::query()
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
            ->where('updated_at', '<', $cutoff)
            ->get();

        $swept = 0;

        foreach ($stranded as $run) {
            $inFlight = $run->steps()
                ->where('step_id', $run->current_step)
                ->where('status', StepStatus::Running->value)
                ->latest('id')
                ->first();

            // A recent in-flight attempt means a worker is genuinely on it.
            if ($inFlight !== null && $inFlight->started_at !== null && $inFlight->started_at->gte($cutoff)) {
                continue;
            }

            // Clear the stale attempt so a fresh claim isn't blocked.
            $inFlight?->update([
                'status' => StepStatus::Failed,
                'error' => 'Superseded by sweep.',
                'finished_at' => now(),
            ]);

            if ($action === 'fail') {
                $failed = WorkflowRun::query()
                    ->whereKey($run->id)
                    ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
                    ->update([
                        'status' => RunStatus::Failed->value,
                        'failed_step' => $run->current_step,
                        'failure_reason' => "Stale for more than {$staleAfter} seconds; swept.",
                        'updated_at' => now(),
                    ]);

                if ($failed === 1) {
                    event(new WorkflowFailed($run->refresh()));
                    $this->line("Failed stale run [{$run->id}] at step [{$run->current_step}].");
                    $swept++;
                }

                continue;
            }

            try {
                WorkflowStepJob::dispatch($run->id, $run->current_step);
                $this->line("Re-dispatched run [{$run->id}] at step [{$run->current_step}].");
                $swept++;
            } catch (Throwable $e) {
                // On the sync driver the re-dispatched step runs inline and
                // its failure surfaces here; the run is marked failed by the
                // job's own failure path. Keep sweeping the rest.
                $this->warn("Run [{$run->id}] failed during swept execution: {$e->getMessage()}");
                $swept++;
            }
        }

        $this->info("Swept {$swept} of {$stranded->count()} stranded run(s).");

        return self::SUCCESS;
    }
}
