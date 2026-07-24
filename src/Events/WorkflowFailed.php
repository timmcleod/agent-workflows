<?php

namespace TimMcLeod\AgentWorkflows\Events;

use Throwable;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowFailed
{
    public function __construct(
        public WorkflowRun $run,
        public ?Throwable $exception = null,
    ) {}
}
