<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Exceptions\MissingWorkflowPromptException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\RiskAnalysisAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;

it('runs an agent step and checkpoints its text output into state', function () {
    SummarizeAgent::fake(['A concise summary.']);

    AgentWorkflow::define('summarize')
        ->step(SummarizeAgent::class)
        ->step(FinalizeStep::class);

    $run = AgentWorkflow::start('summarize', ['doc' => 'A very long document.']);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps']['SummarizeAgent']['text'])->toBe('A concise summary.')
        ->and($run->state['finalized'])->toBeTrue();

    SummarizeAgent::assertPrompted(fn ($prompt) => str_contains((string) $prompt->prompt, 'A very long document.'));
});

it('checkpoints structured agent output and records usage on the step audit row', function () {
    RiskAnalysisAgent::fake([['riskScore' => 9]]);

    AgentWorkflow::define('risk')
        ->step(RiskAnalysisAgent::class);

    $run = AgentWorkflow::start('risk', ['prompt' => 'Assess this contract.']);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps']['RiskAnalysisAgent']['structured'])->toBe(['riskScore' => 9]);

    $step = $run->steps()->sole();

    expect($step->usage)->toBeArray()->toHaveKey('prompt_tokens');
});

it('fails the run when an agent step has no prompt source', function () {
    RiskAnalysisAgent::fake([['riskScore' => 2]]);

    AgentWorkflow::define('promptless')
        ->step(RiskAnalysisAgent::class);

    try {
        AgentWorkflow::start('promptless', []);
        $this->fail('Expected a MissingWorkflowPromptException.');
    } catch (MissingWorkflowPromptException) {
        // expected
    }

    $run = WorkflowRun::sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failed_step)->toBe('RiskAnalysisAgent')
        ->and($run->failure_reason)->toContain('needs a prompt');
});
