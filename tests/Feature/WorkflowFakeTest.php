<?php

use PHPUnit\Framework\AssertionFailedError;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\TransformStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Workflows\ContractReviewWorkflow;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

it('records lifecycle events for assertions while still executing', function () {
    $fake = AgentWorkflow::fake();

    defineWorkflow('under-test', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(TransformStep::class));

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

    defineWorkflow('failing', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(FlakyStep::class));

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

    defineWorkflow('gated', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off')
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('gated', []);

    $fake->assertInterrupted('gated', reason: 'Sign-off');

    $run->resume(['ok' => true]);

    $fake->assertResumed('gated');
    $fake->assertCompleted('gated');
});

it('accepts Workflow class names in name-based assertions, like start() does', function () {
    $fake = AgentWorkflow::fake();

    AgentWorkflow::start(ContractReviewWorkflow::class, []);

    $fake->assertStarted(ContractReviewWorkflow::class);
    $fake->assertCompleted(ContractReviewWorkflow::class);

    // The negative direction must NOT silently pass for a started
    // workflow just because the class string never equals the kebab name.
    expect(fn () => $fake->assertNotStarted(ContractReviewWorkflow::class))
        ->toThrow(AssertionFailedError::class);
});

it('counts step executions across retries', function () {
    $fake = AgentWorkflow::fake();

    FlakyStep::$fail = true;

    defineWorkflow('count-steps', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(FlakyStep::class)
        ->step(FinalizeStep::class));

    try {
        AgentWorkflow::start('count-steps', []);
    } catch (RuntimeException) {
        // expected
    }

    FlakyStep::$fail = false;

    WorkflowRun::sole()->retry();

    // Exactly-once for the committed steps; the flaky step completed
    // once (its failed attempt never completed), the retry re-ran it.
    $fake->assertStepRanTimes(PrepareStep::class, 1);
    $fake->assertStepRanTimes(FlakyStep::class, 1);
    $fake->assertStepRanTimes(FinalizeStep::class, 1);

    expect(fn () => $fake->assertStepRanTimes(PrepareStep::class, 2))
        ->toThrow(AssertionFailedError::class);
});
