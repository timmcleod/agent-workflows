<?php

namespace TimMcLeod\AgentWorkflows\Events;

use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Models\WorkflowStep;

class StepCompleted
{
    public function __construct(
        public WorkflowRun $run,
        public WorkflowStep $step,
    ) {}
}
