<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps;

use TimMcLeod\AgentWorkflows\WorkflowState;

class ConflictBStep
{
    public function __invoke(WorkflowState $state): WorkflowState
    {
        return $state->set('shared', 'beta');
    }
}
