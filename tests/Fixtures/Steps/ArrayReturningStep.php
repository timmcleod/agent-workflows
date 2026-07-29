<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps;

use TimMcLeod\AgentWorkflows\WorkflowState;

class ArrayReturningStep
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(WorkflowState $state): array
    {
        return ['oops' => 'a bare array, not the state'];
    }
}
