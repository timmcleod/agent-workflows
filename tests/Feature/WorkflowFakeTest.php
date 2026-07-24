<?php

use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\TransformStep;

it('records lifecycle events for assertions while still executing', function () {
    $fake = AgentWorkflow::fake();

    AgentWorkflow::define('under-test')
        ->start(PrepareStep::class)
        ->then(TransformStep::class);

    AgentWorkflow::start('under-test', ['value' => 21]);

    $fake->assertStarted('under-test');
    $fake->assertStarted('under-test', fn (WorkflowRun $run) => $run->state['value'] === 21);
    $fake->assertStepRan(PrepareStep::class);
    $fake->assertStepRan('TransformStep');
    $fake->assertStepDidNotRun(FinalizeStep::class);
    $fake->assertCompleted('under-test');
    $fake->assertNotStarted('some-other-workflow');
});

it('asserts failures', function () {
    $fake = AgentWorkflow::fake();

    FlakyStep::$fail = true;

    AgentWorkflow::define('failing')
        ->start(PrepareStep::class)
        ->then(FlakyStep::class);

    try {
        AgentWorkflow::start('failing', []);
    } catch (RuntimeException) {
        // expected
    }

    FlakyStep::$fail = false;

    $fake->assertStarted('failing');
    $fake->assertStepRan(PrepareStep::class);
    $fake->assertFailed('failing');
});

it('asserts nothing started', function () {
    $fake = AgentWorkflow::fake();

    $fake->assertNothingStarted();
});

it('asserts interrupts and resumes', function () {
    $fake = AgentWorkflow::fake();

    AgentWorkflow::define('gated')
        ->start(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off')
        ->then(FinalizeStep::class);

    $run = AgentWorkflow::start('gated', []);

    $fake->assertInterrupted('gated', reason: 'Sign-off');

    $run->resume(['ok' => true]);

    $fake->assertResumed('gated');
    $fake->assertCompleted('gated');
});
