<?php

use Illuminate\Support\Facades\DB;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Exceptions\StateMergeConflictException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\ParallelStepDefinition;
use TimMcLeod\AgentWorkflows\Runtime\StateMerger;
use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\BullCaseAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\RiskAnalysisAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Workflow;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

// The README's flagship pattern: agent branches with NO merge closure.
// Every agent checkpoints under the shared top-level "steps" key, so the
// default merge must union that subtree per step id instead of flagging
// a conflict on the "steps" key itself.

it('merges two agent branches without a merge closure', function () {
    SummarizeAgent::fake(['A concise summary.']);
    RiskAnalysisAgent::fake([['riskScore' => 9]]);

    defineWorkflow('agent-fanout', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([SummarizeAgent::class, RiskAnalysisAgent::class])
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('agent-fanout', ['prompt' => 'Analyze this contract.']);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps']['SummarizeAgent']['text'])->toBe('A concise summary.')
        ->and($run->state['steps']['RiskAnalysisAgent']['structured'])->toBe(['riskScore' => 9])
        ->and($run->state['finalized'])->toBeTrue();
});

it('merges agent branches through a real queued batch', function () {
    config()->set('queue.default', 'database');

    SummarizeAgent::fake(['A concise summary.']);
    RiskAnalysisAgent::fake([['riskScore' => 4]]);

    defineWorkflow('queued-agent-fanout', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([SummarizeAgent::class, RiskAnalysisAgent::class])
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('queued-agent-fanout', ['prompt' => 'Analyze this contract.']);

    $guard = 0;
    while (DB::table('jobs')->count() > 0 && $guard++ < 25) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps']['SummarizeAgent']['text'])->toBe('A concise summary.')
        ->and($run->state['steps']['RiskAnalysisAgent']['structured'])->toBe(['riskScore' => 4])
        ->and($run->state['finalized'])->toBeTrue();
});

it('still throws when branches write conflicting values to the same nested step key', function () {
    $step = new ParallelStepDefinition('parallel:1', [
        new StepDefinition('BranchA', StepType::Callback, 'A'),
        new StepDefinition('BranchB', StepType::Callback, 'B'),
    ]);

    expect(fn () => app(StateMerger::class)->merge($step, [], [
        'BranchA' => ['steps' => ['Shared' => ['text' => 'from-a']]],
        'BranchB' => ['steps' => ['Shared' => ['text' => 'from-b']]],
    ]))->toThrow(StateMergeConflictException::class, 'steps.Shared');
});

it('does not flag branches that leave another step checkpoint untouched', function () {
    $step = new ParallelStepDefinition('parallel:1', [
        new StepDefinition('BranchA', StepType::Callback, 'A'),
        new StepDefinition('BranchB', StepType::Callback, 'B'),
    ]);

    // Both branches inherit the earlier step's checkpoint unchanged in
    // their snapshots — that must not read as a conflict.
    $input = ['steps' => ['Earlier' => ['text' => 'kept']]];

    $merged = app(StateMerger::class)->merge($step, $input, [
        'BranchA' => ['steps' => ['Earlier' => ['text' => 'kept'], 'BranchA' => ['text' => 'a']]],
        'BranchB' => ['steps' => ['Earlier' => ['text' => 'kept'], 'BranchB' => ['text' => 'b']]],
    ]);

    expect($merged->get('steps.Earlier.text'))->toBe('kept')
        ->and($merged->get('steps.BranchA.text'))->toBe('a')
        ->and($merged->get('steps.BranchB.text'))->toBe('b');
});

it('honors the parallel.sync_driver override on the sync queue', function () {
    config()->set('agent-workflows.parallel.sync_driver', 'nonexistent-driver');

    SummarizeAgent::fake(['A concise summary.']);
    RiskAnalysisAgent::fake([['riskScore' => 1]]);

    defineWorkflow('driver-override', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([SummarizeAgent::class, RiskAnalysisAgent::class]));

    try {
        AgentWorkflow::start('driver-override', ['prompt' => 'Analyze this contract.']);
        $this->fail('Expected the unknown concurrency driver to throw.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('nonexistent-driver');
    }
});

it('gives parallel branches their own prompts through pairs', function () {
    SummarizeAgent::fake(['Summarized.']);
    RiskAnalysisAgent::fake([['riskScore' => 3]]);

    defineWorkflow('prompted-fanout', fn (WorkflowDefinition $workflow) => $workflow
        ->parallel([
            [SummarizeAgent::class, 'Summarize the intake: {{ doc }}'],
            'risk' => [RiskAnalysisAgent::class, fn ($state) => 'Assess: '.$state->get('doc')],
        ]));

    $run = AgentWorkflow::start('prompted-fanout', ['doc' => 'Q3 contract']);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps'])->toHaveKeys(['SummarizeAgent', 'risk']);

    // Int-keyed pair: derived id, template interpolated.
    SummarizeAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Summarize the intake: Q3 contract');

    // Alias-keyed pair: aliased id, closure prompt.
    RiskAnalysisAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Assess: Q3 contract');
});

it('runs the same agent twice in one fan-out via int-keyed pairs', function () {
    SummarizeAgent::fake(['For.', 'Against.']);

    defineWorkflow('duplicate-fanout', fn (WorkflowDefinition $workflow) => $workflow
        ->parallel([
            [SummarizeAgent::class, 'Argue for the deal.'],
            [SummarizeAgent::class, 'Argue against the deal.'],
        ]));

    $run = AgentWorkflow::start('duplicate-fanout', []);

    // Derived ids dedupe; no PHP array-key collision anywhere.
    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps'])->toHaveKeys(['SummarizeAgent', 'SummarizeAgent:2']);

    SummarizeAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Argue for the deal.');
    SummarizeAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Argue against the deal.');
});

it('lets a pair prompt beat a conventional prompt method', function () {
    SummarizeAgent::fake(['Bull.']);
    BullCaseAgent::fake(['Case.']);

    $workflow = new class extends Workflow
    {
        public function name(): string
        {
            return 'pair-beats-method';
        }

        public function build(WorkflowDefinition $workflow): WorkflowDefinition
        {
            return $workflow->parallel([
                [SummarizeAgent::class, 'Explicit pair prompt.'],
                BullCaseAgent::class,
            ]);
        }

        protected function summarizeAgentPrompt($state): string
        {
            return 'Should not be used.';
        }

        protected function bullCaseAgentPrompt($state): string
        {
            return 'Bound by convention.';
        }
    };

    app(WorkflowRegistry::class)->forget('pair-beats-method');
    AgentWorkflow::register($workflow);

    $run = AgentWorkflow::start('pair-beats-method', []);

    expect($run->status)->toBe(RunStatus::Completed);

    SummarizeAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Explicit pair prompt.');
    BullCaseAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Bound by convention.');
});

it('rejects branch shapes that are not a class or a pair', function () {
    // The [class => prompt] map form: PHP silently collapses duplicate class
    // keys, so it is refused with guidance toward the positional pair.
    expect(fn () => (new WorkflowDefinition('bad-map'))->parallel([
        SummarizeAgent::class => 'A prompt.',
    ]))->toThrow(InvalidArgumentException::class, 'positional pair');

    expect(fn () => (new WorkflowDefinition('bad-arity'))->parallel([
        [SummarizeAgent::class],
    ]))->toThrow(InvalidArgumentException::class, 'invalid parallel branch');

    expect(fn () => (new WorkflowDefinition('bad-prompt'))->parallel([
        [SummarizeAgent::class, 42],
    ]))->toThrow(InvalidArgumentException::class, 'invalid parallel branch');
});

it('includes pair prompts in the definition hash', function () {
    $one = (new WorkflowDefinition('ph'))->parallel([[SummarizeAgent::class, 'Prompt A.']]);
    $changed = (new WorkflowDefinition('ph'))->parallel([[SummarizeAgent::class, 'Prompt B.']]);

    expect($one->hash())->not->toBe($changed->hash());
});
