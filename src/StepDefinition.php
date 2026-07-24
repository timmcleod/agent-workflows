<?php

namespace TimMcLeod\AgentWorkflows;

use TimMcLeod\AgentWorkflows\Enums\StepType;

class StepDefinition
{
    /**
     * @param  class-string|null  $target
     */
    public function __construct(
        public readonly string $id,
        public readonly StepType $type,
        public readonly ?string $target = null,
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
        ], fn (mixed $value) => $value !== null);
    }
}
