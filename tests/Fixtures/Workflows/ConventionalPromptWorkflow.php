<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Workflows;

use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\BearCaseAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\BullCaseAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\DeployAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Workflow;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

/**
 * Exercises every surface the conventional-prompt binding reaches: a plain
 * agent step, an explicit prompt beating a matching method, a condition
 * branch, a parallel branch, and an evaluate body under an alias.
 */
class ConventionalPromptWorkflow extends Workflow
{
    public function build(WorkflowDefinition $workflow): WorkflowDefinition
    {
        return $workflow
            ->step(SummarizeAgent::class)
            ->step(BullCaseAgent::class, 'Explicit wins.')
            ->when(fn (WorkflowState $state) => (bool) $state->get('escalate'), then: DeployAgent::class)
            ->parallel([BearCaseAgent::class], mode: 'sync')
            ->evaluate(SummarizeAgent::class, until: fn (WorkflowState $state) => true, as: 'memo');
    }

    protected function summarizeAgentPrompt(WorkflowState $state): string
    {
        return 'Summarize the intake: '.$state->get('doc');
    }

    /** Must never be consulted: the step passes an explicit prompt. */
    protected function bullCaseAgentPrompt(WorkflowState $state): string
    {
        return 'Should not be used.';
    }

    protected function deployAgentPrompt(WorkflowState $state): string
    {
        return 'Deploy carefully.';
    }

    protected function bearCaseAgentPrompt(WorkflowState $state): string
    {
        return 'Argue the bear case.';
    }

    protected function memoPrompt(WorkflowState $state): string
    {
        return 'Draft the memo.';
    }
}
