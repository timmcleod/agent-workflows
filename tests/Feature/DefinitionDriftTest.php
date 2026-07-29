<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Exceptions\DefinitionDriftException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

function startFailedDriftingRun(): WorkflowRun
{
    FlakyStep::$fail = true;

    defineWorkflow('drifty', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(FlakyStep::class));

    try {
        AgentWorkflow::start('drifty', []);
    } catch (RuntimeException) {
        // expected
    }

    FlakyStep::$fail = false;

    // A deploy changes the definition while the run is parked in "failed".
    defineWorkflow('drifty', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(FlakyStep::class)
        ->step(FinalizeStep::class));

    return WorkflowRun::sole();
}

it('refuses to resume a drifted definition in strict mode', function () {
    $run = startFailedDriftingRun();

    expect(fn () => $run->retry())->toThrow(DefinitionDriftException::class);

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->failure_reason)->toContain('definition has changed');
});

it('resumes a drifted definition best-effort by step name in loose mode', function () {
    config()->set('agent-workflows.definition_drift', 'loose');

    $run = startFailedDriftingRun();

    $run = $run->retry();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['sequence'])->toBe(['prepare', 'flaky', 'finalize'])
        ->and($run->state['finalized'])->toBeTrue();
});

it('refuses a strict-mode resume before consuming the response, leaving the gate open', function () {
    defineWorkflow('drifty-gate', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off', as: 'gate')
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('drifty-gate', []);

    expect($run->status)->toBe(RunStatus::AwaitingHuman);

    // A deploy changes the definition while the run is parked.
    defineWorkflow('drifty-gate', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off', as: 'gate')
        ->step(FlakyStep::class)
        ->step(FinalizeStep::class));

    expect(fn () => $run->resume(['approved' => true]))
        ->toThrow(DefinitionDriftException::class);

    $run->refresh();

    // The whole transition rolled back: still parked, interrupt open,
    // the human's response not consumed.
    expect($run->status)->toBe(RunStatus::AwaitingHuman)
        ->and($run->interrupts()->whereNull('resolved_at')->count())->toBe(1)
        ->and($run->state)->not->toHaveKey('approved');
});

it('resumes a drifted parked run best-effort in loose mode', function () {
    config()->set('agent-workflows.definition_drift', 'loose');

    defineWorkflow('drifty-gate-loose', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off', as: 'gate')
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('drifty-gate-loose', []);

    defineWorkflow('drifty-gate-loose', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off', as: 'gate')
        ->step(FlakyStep::class)
        ->step(FinalizeStep::class));

    $run = $run->resume(['approved' => true]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['approved'])->toBeTrue()
        ->and($run->state['finalized'])->toBeTrue();
});
