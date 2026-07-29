<?php

namespace TimMcLeod\AgentWorkflows\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use TimMcLeod\AgentWorkflows\AwaitHumanStepDefinition;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Events\WorkflowFailed;
use TimMcLeod\AgentWorkflows\Jobs\WorkflowStepJob;
use TimMcLeod\AgentWorkflows\Models\WorkflowInterrupt;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Models\WorkflowStep;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

/**
 * Recovers runs stranded by the failure modes the engine cannot see:
 * a worker hard-killed mid-step (SIGKILL, OOM, deploy) leaves the run
 * "running" forever, and a process dying between checkpoint commit and
 * queue push leaves a run whose next step was never dispatched. Also
 * enforces awaitHuman() timeouts on parked runs.
 *
 * Idempotent by construction: re-dispatching is safe because step claims
 * reject duplicates, and the conditional advance lets at most one attempt
 * move the cursor.
 */
#[AsCommand(name: 'agent-workflows:sweep')]
class SweepCommand extends Command
{
    protected $signature = 'agent-workflows:sweep';

    protected $description = 'Recover runs stranded by dead workers or lost dispatches, and enforce await timeouts';

    public function handle(WorkflowRegistry $registry): int
    {
        $this->expireTimedOutWaits($registry);

        $staleAfter = (int) config('agent-workflows.sweep.stale_after', 600);
        $action = (string) config('agent-workflows.sweep.action', 'redispatch');
        $cutoff = now()->subSeconds($staleAfter);

        $swept = 0;
        $stranded = 0;

        // Chunked, and without the state column: a fleet crash strands runs
        // in bulk, and this loop must not hydrate thousands of multi-KB
        // checkpoints it never reads.
        WorkflowRun::query()
            ->select(['id', 'name', 'status', 'current_step', 'updated_at'])
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
            ->where('updated_at', '<', $cutoff)
            ->chunkById(100, function ($runs) use ($action, $staleAfter, $cutoff, &$swept, &$stranded) {
                $stranded += $runs->count();

                // One query for the whole chunk instead of one per run.
                $inFlightByRun = WorkflowStep::query()
                    ->whereIn('run_id', $runs->pluck('id'))
                    ->where('status', StepStatus::Running->value)
                    ->orderBy('id')
                    ->get()
                    ->groupBy('run_id');

                foreach ($runs as $run) {
                    $inFlight = $inFlightByRun->get($run->id)
                        ?->where('step_id', $run->current_step)
                        ->last();

                    $swept += $this->sweep($run, $inFlight, $action, $staleAfter, $cutoff) ? 1 : 0;
                }
            });

        $this->info("Swept {$swept} of {$stranded} stranded run(s).");

        return self::SUCCESS;
    }

    protected function sweep(
        WorkflowRun $run,
        ?WorkflowStep $inFlight,
        string $action,
        int $staleAfter,
        Carbon $cutoff,
    ): bool {
        // A recent in-flight attempt means a worker is genuinely on it.
        if ($inFlight !== null && $inFlight->started_at !== null && $inFlight->started_at->gte($cutoff)) {
            return false;
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

                return true;
            }

            return false;
        }

        // Debounce: freshen updated_at so a backlogged queue gets this run
        // re-dispatched once per stale_after window, not once per tick —
        // during an outage the old behavior added a duplicate job per
        // stranded run per sweep, feeding the very backlog it waited on.
        WorkflowRun::query()->whereKey($run->id)->update(['updated_at' => now()]);

        try {
            WorkflowStepJob::dispatch($run->id, $run->current_step);
            $this->line("Re-dispatched run [{$run->id}] at step [{$run->current_step}].");
        } catch (Throwable $e) {
            // On the sync driver the re-dispatched step runs inline and
            // its failure surfaces here; the run is marked failed by the
            // job's own failure path. Keep sweeping the rest.
            $this->warn("Run [{$run->id}] failed during swept execution: {$e->getMessage()}");
        }

        return true;
    }

    /**
     * Act on awaitHuman() waits whose deadline has passed: resume with the
     * step's timeoutResponse when it declares one, otherwise fail the run at
     * the gate (retry() re-arms it with a fresh deadline). The interrupt is
     * deliberately left open on the failure path so a retried run parks on
     * the same wait instead of sailing through a "resolved" gate.
     */
    protected function expireTimedOutWaits(WorkflowRegistry $registry): void
    {
        WorkflowInterrupt::query()
            ->whereNull('resolved_at')
            ->whereNotNull('timeout_at')
            ->where('timeout_at', '<=', now())
            ->whereHas('run', fn ($query) => $query->where('status', RunStatus::AwaitingHuman->value))
            ->with('run')
            ->chunkById(100, function ($due) use ($registry) {
                foreach ($due as $interrupt) {
                    $this->expire($registry, $interrupt);
                }
            });
    }

    protected function expire(WorkflowRegistry $registry, WorkflowInterrupt $interrupt): void
    {
        $run = $interrupt->run;
        $response = $this->timeoutResponseFor($registry, $run, $interrupt);

        if ($response !== null) {
            try {
                $run->resume($response);
                $this->line("Resumed timed-out run [{$run->id}] at [{$interrupt->step_id}] with its timeout response.");
            } catch (Throwable $e) {
                // A concurrent human resume, a validation error in the
                // configured response, or (on the sync driver) a failure
                // further down the run. Keep sweeping the rest.
                $this->warn("Run [{$run->id}] timeout resume failed: {$e->getMessage()}");
            }

            return;
        }

        // Conditional transition: a human resuming at this instant wins.
        $failed = WorkflowRun::query()
            ->whereKey($run->id)
            ->where('status', RunStatus::AwaitingHuman->value)
            ->update([
                'status' => RunStatus::Failed->value,
                'failed_step' => $interrupt->step_id,
                'failure_reason' => "Timed out waiting for a human at [{$interrupt->step_id}].",
                'updated_at' => now(),
            ]);

        if ($failed === 1) {
            event(new WorkflowFailed($run->refresh()));
            $this->line("Failed timed-out run [{$run->id}] at [{$interrupt->step_id}].");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function timeoutResponseFor(WorkflowRegistry $registry, WorkflowRun $run, WorkflowInterrupt $interrupt): ?array
    {
        if (! $registry->has($run->name)) {
            return null;
        }

        $definition = $registry->get($run->name);

        if (! $definition->hasStep($interrupt->step_id)) {
            return null;
        }

        $step = $definition->findStep($interrupt->step_id);

        return $step instanceof AwaitHumanStepDefinition ? $step->timeoutResponse : null;
    }
}
