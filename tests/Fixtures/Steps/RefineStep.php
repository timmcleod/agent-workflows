<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps;

use TimMcLeod\AgentWorkflows\WorkflowState;

class RefineStep
{
    public function __invoke(WorkflowState $state): WorkflowState
    {
        return $state->set('score', (int) $state->get('score', 0) + 3);
    }
}
