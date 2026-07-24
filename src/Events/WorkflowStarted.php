<?php

namespace TimMcLeod\AgentWorkflows\Events;

use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowStarted
{
    public function __construct(public WorkflowRun $run) {}
}
