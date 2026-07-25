<?php

namespace TimMcLeod\AgentWorkflows\Events;

use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowCancelled
{
    public function __construct(public WorkflowRun $run) {}
}
