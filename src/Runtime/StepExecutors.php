<?php

namespace TimMcLeod\AgentWorkflows\Runtime;

use Illuminate\Contracts\Container\Container;
use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\Steps\AgentStepExecutor;
use TimMcLeod\AgentWorkflows\Steps\CallbackStepExecutor;
use TimMcLeod\AgentWorkflows\Steps\StepExecutor;

class StepExecutors
{
    public function __construct(protected Container $container) {}

    public function for(StepDefinition $step): StepExecutor
    {
        return match ($step->type) {
            StepType::Agent => $this->container->make(AgentStepExecutor::class),
            StepType::Callback => $this->container->make(CallbackStepExecutor::class),
            default => throw new WorkflowException(
                "Step type [{$step->type->value}] has no direct executor."
            ),
        };
    }
}
