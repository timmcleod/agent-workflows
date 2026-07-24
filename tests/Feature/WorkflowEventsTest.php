<?php

use Illuminate\Support\Facades\Event;
use TimMcLeod\AgentWorkflows\Events\StepCompleted;
use TimMcLeod\AgentWorkflows\Events\WorkflowCompleted;
use TimMcLeod\AgentWorkflows\Events\WorkflowFailed;
use TimMcLeod\AgentWorkflows\Events\WorkflowStarted;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\TransformStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

it('fires lifecycle events across a successful run', function () {
    Event::fake([WorkflowStarted::class, StepCompleted::class, WorkflowCompleted::class]);

    defineWorkflow('eventful', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(TransformStep::class));

    AgentWorkflow::start('eventful', ['value' => 1]);

    Event::assertDispatched(WorkflowStarted::class, fn ($e) => $e->run->name === 'eventful');
    Event::assertDispatchedTimes(StepCompleted::class, 2);
    Event::assertDispatched(WorkflowCompleted::class, fn ($e) => $e->run->name === 'eventful');
});

it('fires WorkflowFailed when a step fails', function () {
    Event::fake([WorkflowFailed::class]);

    FlakyStep::$fail = true;

    defineWorkflow('doomed', fn (WorkflowDefinition $workflow) => $workflow
        ->step(FlakyStep::class));

    try {
        AgentWorkflow::start('doomed', []);
    } catch (RuntimeException) {
        // expected
    }

    FlakyStep::$fail = false;

    Event::assertDispatched(WorkflowFailed::class, function ($e) {
        return $e->run->name === 'doomed' && $e->exception?->getMessage() === 'Flaky step exploded.';
    });
});
