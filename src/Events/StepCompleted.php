<?php

namespace TimMcLeod\AgentWorkflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Models\WorkflowStep;

class StepCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowRun $run,
        public WorkflowStep $step,
    ) {}
}
