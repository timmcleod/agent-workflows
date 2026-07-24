<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps;

use TimMcLeod\AgentWorkflows\WorkflowState;

class BranchAStep
{
    public function __invoke(WorkflowState $state): WorkflowState
    {
        return $state->set('a', 'from-a');
    }
}
