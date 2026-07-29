<?php

namespace TimMcLeod\AgentWorkflows\Steps;

use Illuminate\Contracts\Container\Container;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

class CallbackStepExecutor implements StepExecutor
{
    public function __construct(protected Container $container) {}

    public function execute(StepDefinition $step, WorkflowState $state): StepResult
    {
        $handler = $this->container->make($step->target);

        $result = $handler($state);

        // A handler returning anything else (a bare array is the common
        // mistake) expected its value to be checkpointed — discarding it
        // silently would lose that work.
        if ($result !== null && ! $result instanceof WorkflowState) {
            throw new WorkflowException(
                "Callback step [{$step->id}] returned [".get_debug_type($result).']; '.
                'return the WorkflowState (or null to checkpoint the mutated instance).'
            );
        }

        return new StepResult($result instanceof WorkflowState ? $result : $state);
    }
}
