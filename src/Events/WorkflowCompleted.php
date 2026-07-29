<?php

namespace TimMcLeod\AgentWorkflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowCompleted implements ShouldDispatchAfterCommit
{
    use SerializesModels;

    public function __construct(public WorkflowRun $run) {}
}
