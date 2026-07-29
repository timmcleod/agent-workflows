<?php

namespace TimMcLeod\AgentWorkflows;

use TimMcLeod\AgentWorkflows\Enums\StepType;

class AwaitEventStepDefinition extends StepDefinition
{
    /**
     * @param  array<string, mixed>|null  $schema  Laravel validation rules
     *                                             the event payload must satisfy
     */
    public function __construct(
        string $id,
        public readonly string $event,
        public readonly ?array $schema = null,
    ) {
        parent::__construct($id, StepType::AwaitEvent);
    }

    public function fingerprint(): array
    {
        return [
            ...parent::fingerprint(),
            'event' => $this->event,
            'schema' => $this->schema,
        ];
    }
}
