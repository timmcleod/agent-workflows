<?php

namespace TimMcLeod\AgentWorkflows\Steps;

use TimMcLeod\AgentWorkflows\WorkflowState;

class StepResult
{
    /**
     * @param  array<string, int>|null  $usage
     */
    public function __construct(
        public readonly WorkflowState $state,
        public readonly ?array $usage = null,
    ) {}
}
