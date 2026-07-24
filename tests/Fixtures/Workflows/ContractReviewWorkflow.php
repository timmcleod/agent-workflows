<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Workflows;

use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Workflow;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

class ContractReviewWorkflow extends Workflow
{
    public function build(WorkflowDefinition $workflow): WorkflowDefinition
    {
        return $workflow
            ->step(PrepareStep::class)
            ->step(FinalizeStep::class);
    }
}
