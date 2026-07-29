<?php

namespace TimMcLeod\AgentWorkflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowCancelled implements ShouldDispatchAfterCommit
{
    public function __construct(public WorkflowRun $run) {}
}
