<?php

namespace TimMcLeod\AgentWorkflows\Contracts;

use TimMcLeod\AgentWorkflows\WorkflowState;

/**
 * Implement on an SDK agent class to derive its prompt from workflow state.
 * Agents without this interface fall back to the state's "prompt" key.
 */
interface HasWorkflowPrompt
{
    public function workflowPrompt(WorkflowState $state): string;
}
