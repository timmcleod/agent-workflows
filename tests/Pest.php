<?php

use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\TestCase;
use TimMcLeod\AgentWorkflows\Workflow;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Register an ad hoc class-based workflow for the current test.
 *
 * @param  Closure(WorkflowDefinition): WorkflowDefinition  $build
 */
function defineWorkflow(string $name, Closure $build): WorkflowDefinition
{
    return AgentWorkflow::register(new class($name, $build) extends Workflow
    {
        public function __construct(
            protected string $workflowName,
            protected Closure $builder,
        ) {}

        public function name(): string
        {
            return $this->workflowName;
        }

        public function build(WorkflowDefinition $workflow): WorkflowDefinition
        {
            return ($this->builder)($workflow);
        }
    });
}
