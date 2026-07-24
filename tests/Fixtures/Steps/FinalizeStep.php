<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps;

use TimMcLeod\AgentWorkflows\WorkflowState;

class FinalizeStep
{
    public function __invoke(WorkflowState $state): WorkflowState
    {
        return $state
            ->set('finalized', true)
            ->set('sequence', [...$state->get('sequence', []), 'finalize']);
    }
}
