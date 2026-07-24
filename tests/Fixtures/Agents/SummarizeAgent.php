<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;
use TimMcLeod\AgentWorkflows\Contracts\HasWorkflowPrompt;
use TimMcLeod\AgentWorkflows\WorkflowState;

class SummarizeAgent implements Agent, HasWorkflowPrompt
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Summarize the given document.';
    }

    public function workflowPrompt(WorkflowState $state): string
    {
        return 'Summarize: '.$state->get('doc');
    }
}
