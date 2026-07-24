<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps;

use TimMcLeod\AgentWorkflows\WorkflowState;

class TransformStep
{
    public function __invoke(WorkflowState $state): WorkflowState
    {
        return $state
            ->set('value', (int) $state->get('value', 0) * 2)
            ->set('sequence', [...$state->get('sequence', []), 'transform']);
    }
}
