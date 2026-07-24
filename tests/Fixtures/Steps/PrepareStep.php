<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps;

use TimMcLeod\AgentWorkflows\WorkflowState;

class PrepareStep
{
    public function __invoke(WorkflowState $state): WorkflowState
    {
        return $state
            ->set('prepared', true)
            ->set('sequence', [...$state->get('sequence', []), 'prepare']);
    }
}
