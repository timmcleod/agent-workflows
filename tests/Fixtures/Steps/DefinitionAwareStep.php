<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps;

use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

class DefinitionAwareStep
{
    public function __invoke(WorkflowState $state, StepDefinition $step): WorkflowState
    {
        return $state->set('seen_step_id', $step->id);
    }
}
