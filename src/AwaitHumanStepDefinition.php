<?php

namespace TimMcLeod\AgentWorkflows;

use TimMcLeod\AgentWorkflows\Enums\StepType;

class AwaitHumanStepDefinition extends StepDefinition
{
    /**
     * @param  array<string, mixed>|null  $schema  Laravel validation rules the
     *                                             resume payload must satisfy
     * @param  int|null  $timeout  seconds the run may wait before the sweeper
     *                             acts on it
     * @param  array<string, mixed>|null  $timeoutResponse  payload the run is
     *                                                      resumed with on timeout;
     *                                                      without one, timing out
     *                                                      fails the run
     */
    public function __construct(
        string $id,
        public readonly ?string $reason = null,
        public readonly ?array $schema = null,
        public readonly ?int $timeout = null,
        public readonly ?array $timeoutResponse = null,
        ?string $label = null,
    ) {
        parent::__construct($id, StepType::AwaitHuman, label: $label);
    }

    public function displayLabel(): string
    {
        // The reason is already the human-facing description of the wait.
        return $this->label ?? $this->reason ?? 'Waiting for a person';
    }

    public function fingerprint(): array
    {
        // The reason is cosmetic — editing it should not strand in-flight
        // runs in strict drift mode. The schema, timeout, and timeout
        // response change behavior, so they count.
        return [
            ...parent::fingerprint(),
            'schema' => $this->schema,
            'timeout' => $this->timeout,
            'timeoutResponse' => $this->timeoutResponse,
        ];
    }
}
