<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps;

use TimMcLeod\AgentWorkflows\Steps\StepResult;
use TimMcLeod\AgentWorkflows\WorkflowState;

class UsageReportingStep
{
    public function __invoke(WorkflowState $state): StepResult
    {
        return new StepResult(
            $state->set('worked', true),
            ['prompt_tokens' => 5, 'completion_tokens' => 7],
        );
    }
}
