<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\RiskAnalysisAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\States\ReviewState;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\BranchAStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\BranchBStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\TypedDecisionStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

it('hydrates the declared state class for steps, prompts, and conditions', function () {
    RiskAnalysisAgent::fake([['riskScore' => 9, 'rationale' => 'Bad terms.']]);
    SummarizeAgent::fake(['Escalation note.']);

    $promptSaw = null;
    $conditionSaw = null;

    defineWorkflow('typed-review', function (WorkflowDefinition $workflow) use (&$promptSaw, &$conditionSaw) {
        return $workflow
            ->step(RiskAnalysisAgent::class, prompt: function (ReviewState $state) use (&$promptSaw) {
                $promptSaw = $state::class;

                return 'Assess: '.$state->document();
            })
            ->step(TypedDecisionStep::class)
            ->when(function (ReviewState $state) use (&$conditionSaw) {
                $conditionSaw = $state::class;

                return $state->isHighRisk();
            }, then: SummarizeAgent::class, thenPrompt: 'Escalate.');
    }, ReviewState::class);

    $run = AgentWorkflow::start('typed-review', ['doc' => 'The contract.']);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($promptSaw)->toBe(ReviewState::class)
        ->and($conditionSaw)->toBe(ReviewState::class)
        ->and($run->state['received_class'])->toBe(ReviewState::class)
        ->and($run->state['decision'])->toBe('escalate');
});

it('rehydrates the state class from the checkpoint after a human interrupt', function () {
    defineWorkflow('typed-gate', fn (WorkflowDefinition $workflow) => $workflow
        ->awaitHuman(reason: 'Sign off', schema: ['approved' => 'required|boolean'], as: 'gate')
        ->step(TypedDecisionStep::class), ReviewState::class);

    $run = AgentWorkflow::start('typed-gate', ['doc' => 'x']);

    expect($run->status)->toBe(RunStatus::AwaitingHuman)
        ->and($run->workflowState())->toBeInstanceOf(ReviewState::class);

    $run = $run->resume(['approved' => true]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['received_class'])->toBe(ReviewState::class);
});

it('hydrates the state class for parallel merges', function () {
    defineWorkflow('typed-parallel', fn (WorkflowDefinition $workflow) => $workflow
        ->parallel([BranchAStep::class, BranchBStep::class])
        ->step(TypedDecisionStep::class), ReviewState::class);

    $run = AgentWorkflow::start('typed-parallel', ['doc' => 'x']);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['received_class'])->toBe(ReviewState::class);
});

it('falls back to the base state class when the workflow is not registered', function () {
    defineWorkflow('typed-orphan', fn (WorkflowDefinition $workflow) => $workflow
        ->awaitHuman(reason: 'Hold'), ReviewState::class);

    $run = AgentWorkflow::start('typed-orphan', []);
    $run->update(['name' => 'gone']);

    expect($run->refresh()->workflowState())->toBeInstanceOf(WorkflowState::class)
        ->not->toBeInstanceOf(ReviewState::class);
});

it('defaults to the base WorkflowState when no state class is declared', function () {
    defineWorkflow('untyped', fn (WorkflowDefinition $workflow) => $workflow
        ->awaitHuman(reason: 'Hold'));

    $run = AgentWorkflow::start('untyped', []);

    expect($run->workflowState())->toBeInstanceOf(WorkflowState::class)
        ->and($run->workflowState()::class)->toBe(WorkflowState::class);
});

it('rejects a state class that does not extend WorkflowState', function () {
    new WorkflowDefinition('bad-state', stateClass: WorkflowRun::class);
})->throws(InvalidArgumentException::class, 'must be or extend WorkflowState');

it('exposes step outputs through output() without structural paths', function () {
    RiskAnalysisAgent::fake([['riskScore' => 4, 'rationale' => 'Mild.']]);
    SummarizeAgent::fake(['A summary.']);

    defineWorkflow('outputs', fn (WorkflowDefinition $workflow) => $workflow
        ->step(RiskAnalysisAgent::class, prompt: 'Assess.')
        ->step(SummarizeAgent::class, prompt: 'Summarize.'));

    $state = AgentWorkflow::start('outputs', [])->workflowState();

    expect($state->output(RiskAnalysisAgent::class)?->structured('riskScore'))->toBe(4)
        ->and($state->output(RiskAnalysisAgent::class)?->structured())->toBe(['riskScore' => 4, 'rationale' => 'Mild.'])
        ->and($state->output(SummarizeAgent::class)?->text())->toBe('A summary.')
        ->and($state->output('SummarizeAgent')?->text())->toBe('A summary.')
        ->and($state->output('NeverRan'))->toBeNull();
});
