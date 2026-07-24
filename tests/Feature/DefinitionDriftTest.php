<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Exceptions\DefinitionDriftException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;

function startFailedDriftingRun(): WorkflowRun
{
    FlakyStep::$fail = true;

    AgentWorkflow::define('drifty')
        ->step(PrepareStep::class)
        ->step(FlakyStep::class);

    try {
        AgentWorkflow::start('drifty', []);
    } catch (RuntimeException) {
        // expected
    }

    FlakyStep::$fail = false;

    // A deploy changes the definition while the run is parked in "failed".
    AgentWorkflow::define('drifty')
        ->step(PrepareStep::class)
        ->step(FlakyStep::class)
        ->step(FinalizeStep::class);

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
