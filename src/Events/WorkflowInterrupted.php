<?php

namespace TimMcLeod\AgentWorkflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use TimMcLeod\AgentWorkflows\Models\WorkflowInterrupt;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowInterrupted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowRun $run,
        public WorkflowInterrupt $interrupt,
    ) {}
}
