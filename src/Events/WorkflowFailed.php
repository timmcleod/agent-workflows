<?php

namespace TimMcLeod\AgentWorkflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Throwable;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowRun $run,
        public ?Throwable $exception = null,
    ) {}
}
