<?php

use Illuminate\Support\Facades\DB;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Exceptions\StateMergeConflictException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\ParallelStepDefinition;
use TimMcLeod\AgentWorkflows\Runtime\StateMerger;
use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\RiskAnalysisAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

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
