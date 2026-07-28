<?php

namespace TimMcLeod\AgentWorkflows\Interrupts;

use Illuminate\Support\Carbon;
use TimMcLeod\AgentWorkflows\Enums\InterruptType;

/**
 * A step's request to pause the run, before it is persisted as a
 * WorkflowInterrupt row.
 */
class PendingInterrupt
{
    /**
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>|null  $context
     * @param  Carbon|null  $timeoutAt  when the sweeper may act on the wait
     */
    public function __construct(
        public readonly InterruptType $type,
        public readonly ?string $reason = null,
        public readonly ?array $schema = null,
        public readonly ?array $context = null,
        public readonly ?Carbon $timeoutAt = null,
    ) {}
}
