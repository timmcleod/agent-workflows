<?php

namespace TimMcLeod\AgentWorkflows;

use Closure;
use TimMcLeod\AgentWorkflows\Enums\StepType;

class EvaluateStepDefinition extends StepDefinition
{
    /**
     * The body shares this step's id, so its output (and the iteration
     * counter) live under one "steps.{id}" key in state.
     *
     * @param  Closure(WorkflowState): bool  $until
     */
    public function __construct(
        string $id,
        public readonly StepDefinition $body,
        public readonly Closure $until,
        public readonly int $maxIterations = 3,
        ?string $label = null,
    ) {
        parent::__construct($id, StepType::Evaluate, label: $label);
    }

    public function fingerprint(): array
    {
        return [
            ...parent::fingerprint(),
            'body' => $this->body->fingerprint(),
            'maxIterations' => $this->maxIterations,
        ];
    }
}
