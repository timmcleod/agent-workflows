<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Exceptions\MissingWorkflowPromptException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\RiskAnalysisAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

it('runs an agent step with a step-level prompt closure', function () {
    SummarizeAgent::fake(['A concise summary.']);

    defineWorkflow('summarize', fn (WorkflowDefinition $workflow) => $workflow
        ->step(SummarizeAgent::class, prompt: fn ($state) => 'Summarize: '.$state->get('doc'))
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('summarize', ['doc' => 'A very long document.']);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps']['SummarizeAgent']['text'])->toBe('A concise summary.')
        ->and($run->state['finalized'])->toBeTrue();

    SummarizeAgent::assertPrompted(fn ($prompt) => (string) $prompt->prompt === 'Summarize: A very long document.');
});

it('runs an agent step with a static string prompt', function () {
    SummarizeAgent::fake(['Done.']);

    defineWorkflow('static-prompt', fn (WorkflowDefinition $workflow) => $workflow
        ->step(SummarizeAgent::class, prompt: 'Summarize the standard weekly report.'));

    $run = AgentWorkflow::start('static-prompt', []);

    expect($run->status)->toBe(RunStatus::Completed);

    SummarizeAgent::assertPrompted(fn ($prompt) => (string) $prompt->prompt === 'Summarize the standard weekly report.');
});

it('lets the same agent take different prompts in different workflows', function () {
    SummarizeAgent::fake(['One.', 'Two.']);

    defineWorkflow('flow-one', fn (WorkflowDefinition $workflow) => $workflow
        ->step(SummarizeAgent::class, prompt: fn ($s) => 'Summarize the contract: '.$s->get('contract')));

    defineWorkflow('flow-two', fn (WorkflowDefinition $workflow) => $workflow
        ->step(SummarizeAgent::class, prompt: fn ($s) => 'Summarize the ticket: '.$s->get('ticket')));

    AgentWorkflow::start('flow-one', ['contract' => 'C1']);
    AgentWorkflow::start('flow-two', ['ticket' => 'T1']);

    SummarizeAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Summarize the contract: C1');
    SummarizeAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Summarize the ticket: T1');
});

it('checkpoints structured agent output and records usage on the step audit row', function () {
    RiskAnalysisAgent::fake([['riskScore' => 9]]);

    defineWorkflow('risk', fn (WorkflowDefinition $workflow) => $workflow
        ->step(RiskAnalysisAgent::class));

    $run = AgentWorkflow::start('risk', ['prompt' => 'Assess this contract.']);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps']['RiskAnalysisAgent']['structured'])->toBe(['riskScore' => 9])
        // The text form is the same JSON again — not checkpointed.
        ->and($run->state['steps']['RiskAnalysisAgent'])->not->toHaveKey('text');

    $step = $run->steps()->sole();

    expect($step->usage)->toBeArray()->toHaveKey('prompt_tokens');
});

it('fails the run when an agent step has no prompt source', function () {
    RiskAnalysisAgent::fake([['riskScore' => 2]]);

    defineWorkflow('promptless', fn (WorkflowDefinition $workflow) => $workflow
        ->step(RiskAnalysisAgent::class));

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
