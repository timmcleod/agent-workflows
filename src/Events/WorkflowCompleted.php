<?php

namespace TimMcLeod\AgentWorkflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(public WorkflowRun $run) {}
}
