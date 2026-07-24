<?php

namespace TimMcLeod\AgentWorkflows\Interrupts;

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
     */
    public function __construct(
        public readonly InterruptType $type,
        public readonly ?string $reason = null,
        public readonly ?array $schema = null,
        public readonly ?array $context = null,
    ) {}
}
