<?php

namespace TimMcLeod\AgentWorkflows;

use TimMcLeod\AgentWorkflows\Enums\StepType;

class AwaitEventStepDefinition extends StepDefinition
{
    public function __construct(
        string $id,
        public readonly string $event,
    ) {
        parent::__construct($id, StepType::AwaitEvent);
    }

    public function fingerprint(): array
    {
        return [
            ...parent::fingerprint(),
            'event' => $this->event,
        ];
    }
}
