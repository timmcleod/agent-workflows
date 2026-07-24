<?php

namespace TimMcLeod\AgentWorkflows\Steps;

use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

interface StepExecutor
{
    public function execute(StepDefinition $step, WorkflowState $state): StepResult;
}
