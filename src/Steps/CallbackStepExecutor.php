<?php

namespace TimMcLeod\AgentWorkflows\Steps;

use Illuminate\Contracts\Container\Container;
use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

class CallbackStepExecutor implements StepExecutor
{
    public function __construct(protected Container $container) {}

    public function execute(StepDefinition $step, WorkflowState $state): StepResult
    {
        $handler = $this->container->make($step->target);

        $result = $handler($state);

        return new StepResult($result instanceof WorkflowState ? $result : $state);
    }
}
