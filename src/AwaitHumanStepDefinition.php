<?php

namespace TimMcLeod\AgentWorkflows;

use TimMcLeod\AgentWorkflows\Enums\StepType;

class AwaitHumanStepDefinition extends StepDefinition
{
    /**
     * @param  array<string, mixed>|null  $schema  Laravel validation rules the
     *                                             resume payload must satisfy
     */
    public function __construct(
        string $id,
        public readonly ?string $reason = null,
        public readonly ?array $schema = null,
    ) {
        parent::__construct($id, StepType::AwaitHuman);
    }

    public function fingerprint(): array
    {
        // The reason is cosmetic — editing it should not strand in-flight
        // runs in strict drift mode. The schema changes behavior, so it counts.
        return [
            ...parent::fingerprint(),
            'schema' => $this->schema,
        ];
    }
}
