<?php

namespace TimMcLeod\AgentWorkflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;
use Throwable;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowFailed implements ShouldDispatchAfterCommit
{
    use SerializesModels {
        __serialize as serializeModels;
    }

    /**
     * The exception is available to synchronous listeners only: Throwables
     * rarely survive serialization (closures in traces), so queued
     * listeners receive null — read the run's failure_reason instead.
     */
    public function __construct(
        public WorkflowRun $run,
        public ?Throwable $exception = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [...$this->serializeModels(), 'exception' => null];
    }
}
