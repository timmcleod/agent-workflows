<?php

namespace TimMcLeod\AgentWorkflows\Support;

/**
 * SDK-agnostic projection of an agent response — the only shape the engine
 * ever sees, so laravel/ai response changes are absorbed in AgentAdapter.
 */
class AgentStepResult
{
    /**
     * @param  array<string, mixed>|null  $structured
     * @param  array<string, int>  $usage
     * @param  array<int, array{id: string, tool: string, arguments: array<string, mixed>, reason: string|null}>  $pendingApprovals
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $structured,
        public readonly array $usage,
        public readonly ?string $conversationId,
        public readonly array $pendingApprovals = [],
    ) {}

    public function hasPendingApprovals(): bool
    {
        return $this->pendingApprovals !== [];
    }
}
