<?php

namespace TimMcLeod\AgentWorkflows\Runtime;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use TimMcLeod\AgentWorkflows\Events\WorkflowGroupSettled;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

/**
 * Settles a run group after a terminal transition: when no member remains
 * active, every terminal member not yet stamped settled_at is claimed and
 * carried in one WorkflowGroupSettled event.
 *
 * The guarantee is exactly-once STAMPING: each run is claimed by a guarded
 * per-row update, so concurrent settles partition the runs between them —
 * never a duplicate claim, even where lockForUpdate does not serialize
 * readers. Event delivery itself is dispatched after the stamping
 * transaction commits, with the same guarantee as the package's other
 * lifecycle events; the sweeper re-settles groups whose settle never ran
 * (see SweepCommand::resettleGroups()).
 */
class GroupSettler
{
    public function settle(?string $groupKey): void
    {
        if ($groupKey === null) {
            return;
        }

        $settled = DB::transaction(function () use ($groupKey): Collection {
            $members = WorkflowRun::query()
                ->where('group_key', $groupKey)
                ->lockForUpdate()
                ->get();

            if ($members->isEmpty() || $members->contains(fn (WorkflowRun $run) => $run->status->isActive())) {
                return new Collection;
            }

            // Claimed stamping: only the settler whose guarded update wins
            // a row delivers that run.
            $claimed = $members
                ->whereNull('settled_at')
                ->filter(fn (WorkflowRun $run) => WorkflowRun::query()
                    ->whereKey($run->id)
                    ->whereNull('settled_at')
                    ->update(['settled_at' => now(), 'updated_at' => now()]) === 1);

            if ($claimed->isEmpty()) {
                return new Collection;
            }

            return WorkflowRun::query()->findMany($claimed->modelKeys())->toBase();
        });

        if ($settled->isNotEmpty()) {
            event(new WorkflowGroupSettled($groupKey, $settled->values()));
        }
    }
}
