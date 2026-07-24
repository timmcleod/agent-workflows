<?php

namespace TimMcLeod\AgentWorkflows;

use TimMcLeod\AgentWorkflows\Enums\StepType;

class StepDefinition
{
    /**
     * @param  class-string  $target
     */
    public function __construct(
        public readonly string $id,
        public readonly StepType $type,
        public readonly string $target,
    ) {}

    /**
     * @return array{id: string, type: string, target: string}
     */
    public function fingerprint(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'target' => $this->target,
        ];
    }
}
