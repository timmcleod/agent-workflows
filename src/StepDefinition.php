<?php

namespace TimMcLeod\AgentWorkflows;

use Closure;
use TimMcLeod\AgentWorkflows\Enums\StepType;

class StepDefinition
{
    /**
     * @param  class-string|null  $target
     * @param  Closure(WorkflowState): string|string|null  $prompt  agent steps only
     */
    public function __construct(
        public readonly string $id,
        public readonly StepType $type,
        public readonly ?string $target = null,
        public readonly Closure|string|null $prompt = null,
    ) {}

    /**
     * Nested steps addressable by id (condition branches, parallel branches).
     *
     * @return array<int, StepDefinition>
     */
    public function children(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function fingerprint(): array
    {
        return array_filter([
            'id' => $this->id,
            'type' => $this->type->value,
            'target' => $this->target,
            // A string prompt is part of the step's behavior; closure
            // bodies cannot be hashed (documented limitation).
            'prompt' => $this->prompt instanceof Closure ? '(closure)' : $this->prompt,
        ], fn (mixed $value) => $value !== null);
    }
}
