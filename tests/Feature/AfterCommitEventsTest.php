<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use TimMcLeod\AgentWorkflows\Events\WorkflowStarted;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

// Job dispatches are afterCommit; the paired lifecycle events must
// observe the same boundary, or listeners notify about runs that a
// rollback then erases.

it('holds lifecycle events until the caller transaction commits', function () {
    defineWorkflow('transactional', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    $observed = [];
    Event::listen(WorkflowStarted::class, function () use (&$observed) {
        $observed[] = 'started';
    });

    DB::transaction(function () use (&$observed) {
        AgentWorkflow::start('transactional', []);

        // Inside the transaction nothing has been published to listeners.
        expect($observed)->toBe([]);
    });

    expect($observed)->toBe(['started']);
});

it('drops lifecycle events when the caller transaction rolls back', function () {
    defineWorkflow('rolled-back', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    $observed = [];
    Event::listen(WorkflowStarted::class, function () use (&$observed) {
        $observed[] = 'started';
    });

    try {
        DB::transaction(function () {
            AgentWorkflow::start('rolled-back', []);

            throw new RuntimeException('Something else in the transaction failed.');
        });
    } catch (RuntimeException) {
        // expected
    }

    // The run row was rolled away — no listener ever heard about it.
    expect($observed)->toBe([])
        ->and(WorkflowRun::count())->toBe(0);
});
