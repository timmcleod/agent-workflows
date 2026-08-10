<?php

namespace TimMcLeod\AgentWorkflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

/**
 * A run group settled: a member reached a terminal status and no members
 * remain active. Carries every terminal run this settle claimed — each run
 * outcome is stamped settled exactly once, so no two settles carry the same
 * outcome and listeners need no locks or markers of their own. Dispatch
 * happens after the stamping transaction commits, with the same delivery
 * guarantee as the package's other lifecycle events.
 */
class WorkflowGroupSettled implements ShouldDispatchAfterCommit
{
    use SerializesModels;

    /**
     * @param  Collection<int, WorkflowRun>  $runs  the runs delivered in THIS settle
     */
    public function __construct(
        public string $groupKey,
        public Collection $runs,
    ) {}
}
