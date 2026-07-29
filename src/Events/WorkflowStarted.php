<?php

namespace TimMcLeod\AgentWorkflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowStarted implements ShouldDispatchAfterCommit
{
    public function __construct(public WorkflowRun $run) {}
}
