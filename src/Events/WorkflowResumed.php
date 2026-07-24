<?php

namespace TimMcLeod\AgentWorkflows\Events;

use TimMcLeod\AgentWorkflows\Models\WorkflowInterrupt;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowResumed
{
    public function __construct(
        public WorkflowRun $run,
        public WorkflowInterrupt $interrupt,
    ) {}
}
