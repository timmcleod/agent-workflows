<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Exceptions\MissingWorkflowPromptException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\BearCaseAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\BullCaseAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\DeployAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\RiskAnalysisAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\VerdictAgent;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

it('interpolates state paths into string prompts', function () {
    SummarizeAgent::fake(['Done.']);

    defineWorkflow('templated', fn (WorkflowDefinition $workflow) => $workflow
        ->step(SummarizeAgent::class, 'Summarize {{ document.title }} for {{client}}, urgent: {{ urgent }}.'));

    $run = AgentWorkflow::start('templated', [
        'document' => ['title' => 'Q3 Contract'],
        'client' => 'Acme',
        'urgent' => true,
    ]);

    expect($run->status)->toBe(RunStatus::Completed);

    SummarizeAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Summarize Q3 Contract for Acme, urgent: true.');
});

it('chains steps through output placeholders', function () {
    SummarizeAgent::fake(['A tidy summary.']);
    RiskAnalysisAgent::fake([['riskScore' => 8, 'flags' => ['ip', 'liability']]]);
    DeployAgent::fake(['Escalated.']);

    defineWorkflow('chained', fn (WorkflowDefinition $workflow) => $workflow
        ->step(SummarizeAgent::class, 'Summarize the intake.')
        ->step(RiskAnalysisAgent::class, 'Assess the risk of: {{ output:SummarizeAgent }}')
        ->step(DeployAgent::class, 'Risk {{ output:RiskAnalysisAgent.riskScore }}, flags {{ output:RiskAnalysisAgent.flags }}: escalate.'));

    $run = AgentWorkflow::start('chained', []);

    expect($run->status)->toBe(RunStatus::Completed);

    // Bare output: the prior step's text.
    RiskAnalysisAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Assess the risk of: A tidy summary.');

    // Structured paths resolve; arrays JSON-encode.
    DeployAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Risk 8, flags ["ip","liability"]: escalate.');
});

it('fails loudly when a placeholder cannot be resolved', function () {
    SummarizeAgent::fake(['Done.']);

    defineWorkflow('holey', fn (WorkflowDefinition $workflow) => $workflow
        ->step(SummarizeAgent::class, 'Summarize {{ missing_key }}.'));

    try {
        AgentWorkflow::start('holey', []);
        $this->fail('Expected a MissingWorkflowPromptException.');
    } catch (MissingWorkflowPromptException) {
        // The sync queue unwinds the step failure into the caller.
    }

    $run = WorkflowRun::sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failure_reason)->toContain('{{ missing_key }}');
});

it('never interpolates closure results or the state prompt fallback', function () {
    SummarizeAgent::fake(['One.', 'Two.']);

    defineWorkflow('literal', fn (WorkflowDefinition $workflow) => $workflow
        // A closure already has state access; its return is the prompt, verbatim.
        ->step(SummarizeAgent::class, fn ($state) => 'Literal {{ contract }} stays.', as: 'closure-step')
        // Runtime-supplied text must not become a template.
        ->step(SummarizeAgent::class, as: 'fallback-step'));

    $run = AgentWorkflow::start('literal', ['prompt' => 'Fallback {{ contract }} stays.', 'contract' => 'X']);

    expect($run->status)->toBe(RunStatus::Completed);

    SummarizeAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Literal {{ contract }} stays.');
    SummarizeAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Fallback {{ contract }} stays.');
});

it('leaves JSON braces in prompts untouched', function () {
    SummarizeAgent::fake(['Done.']);

    defineWorkflow('jsonish', fn (WorkflowDefinition $workflow) => $workflow
        ->step(SummarizeAgent::class, 'Respond as {"score": 1, "nested": {"ok": true}} exactly.'));

    $run = AgentWorkflow::start('jsonish', []);

    expect($run->status)->toBe(RunStatus::Completed);

    SummarizeAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Respond as {"score": 1, "nested": {"ok": true}} exactly.');
});

it('interpolates debate topics', function () {
    BullCaseAgent::fake(['Bull.']);
    BearCaseAgent::fake(['Bear.']);
    VerdictAgent::fake([['consensus' => true, 'summary' => 'Agreed.']]);

    defineWorkflow('templated-debate', fn (WorkflowDefinition $workflow) => $workflow
        ->debate(
            ['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class],
            judge: VerdictAgent::class,
            as: 'thesis',
            rounds: 2,
            topic: 'Should we acquire {{ target_company }}?',
        ));

    $run = AgentWorkflow::start('templated-debate', ['target_company' => 'Initech']);

    expect($run->status)->toBe(RunStatus::Completed);

    BullCaseAgent::assertPrompted(fn ($p) => str_contains((string) $p->prompt, 'Should we acquire Initech?'));
});
