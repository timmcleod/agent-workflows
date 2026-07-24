<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps;

use TimMcLeod\AgentWorkflows\WorkflowState;

class BranchBStep
{
    public function __invoke(WorkflowState $state): WorkflowState
    {
        return $state->set('b', 'from-b');
    }
}
