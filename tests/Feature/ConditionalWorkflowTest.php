<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\TransformStep;
use TimMcLeod\AgentWorkflows\WorkflowState;

beforeEach(function () {
    FlakyStep::$fail = false;

    AgentWorkflow::define('branching')
        ->step(PrepareStep::class)
        ->when(fn (WorkflowState $s) => $s->get('value') > 10,
            then: TransformStep::class,
            else: FinalizeStep::class)
        ->step(FlakyStep::class);
});

it('routes to the then-branch and continues after it', function () {
    $run = AgentWorkflow::start('branching', ['value' => 42]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['sequence'])->toBe(['prepare', 'transform', 'flaky'])
        ->and($run->state['steps']['when:2']['branch'])->toBe('TransformStep')
        ->and($run->state)->not->toHaveKey('finalized')
        ->and($run->steps()->where('step_id', 'FinalizeStep')->count())->toBe(0);
});

it('routes to the else-branch and continues after it', function () {
    $run = AgentWorkflow::start('branching', ['value' => 1]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['sequence'])->toBe(['prepare', 'finalize', 'flaky'])
        ->and($run->state['steps']['when:2']['branch'])->toBe('FinalizeStep')
        ->and($run->steps()->where('step_id', 'TransformStep')->count())->toBe(0);
});

it('skips ahead when the condition is false and there is no else-branch', function () {
    AgentWorkflow::define('maybe')
        ->step(PrepareStep::class)
        ->when(fn (WorkflowState $s) => false, then: TransformStep::class)
        ->step(FinalizeStep::class);

    $run = AgentWorkflow::start('maybe', ['value' => 1]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['sequence'])->toBe(['prepare', 'finalize'])
        ->and($run->state['steps']['when:2']['branch'])->toBe('skipped');
});

it('completes the run when a trailing condition skips', function () {
    AgentWorkflow::define('trailing')
        ->step(PrepareStep::class)
        ->when(fn () => false, then: TransformStep::class);

    $run = AgentWorkflow::start('trailing', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['sequence'])->toBe(['prepare']);
});
