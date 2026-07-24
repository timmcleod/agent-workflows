<?php

namespace TimMcLeod\AgentWorkflows;

use Closure;
use TimMcLeod\AgentWorkflows\Enums\StepType;

class ConditionStepDefinition extends StepDefinition
{
    /**
     * @param  Closure(WorkflowState): bool  $condition
     */
    public function __construct(
        string $id,
        public readonly Closure $condition,
        public readonly StepDefinition $whenTrue,
        public readonly ?StepDefinition $whenFalse = null,
    ) {
        parent::__construct($id, StepType::Condition);
    }

    public function children(): array
    {
        return array_filter([$this->whenTrue, $this->whenFalse]);
    }

    public function fingerprint(): array
    {
        return [
            ...parent::fingerprint(),
            'then' => $this->whenTrue->fingerprint(),
            'else' => $this->whenFalse?->fingerprint(),
        ];
    }
}
