<?php

use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\TestCase;
use TimMcLeod\AgentWorkflows\Workflow;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;
use TimMcLeod\AgentWorkflows\WorkflowState;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Register an ad hoc class-based workflow for the current test.
 *
 * @param  Closure(WorkflowDefinition): WorkflowDefinition  $build
 * @param  class-string<WorkflowState>|null  $stateClass
 */
function defineWorkflow(string $name, Closure $build, ?string $stateClass = null): WorkflowDefinition
{
    // Tests redefine names freely (e.g. to simulate a deploy changing a
    // definition mid-run); drop any previous registration first.
    app(WorkflowRegistry::class)->forget($name);

    return AgentWorkflow::register(new class($name, $build, $stateClass) extends Workflow
    {
        public function __construct(
            protected string $workflowName,
            protected Closure $builder,
            protected ?string $customStateClass,
        ) {}

        public function name(): string
        {
            return $this->workflowName;
        }

        public function stateClass(): string
        {
            return $this->customStateClass ?? parent::stateClass();
        }

        public function build(WorkflowDefinition $workflow): WorkflowDefinition
        {
            return ($this->builder)($workflow);
        }
    });
}
