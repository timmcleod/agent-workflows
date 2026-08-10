<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

beforeEach(function () {
    FlakyStep::$fail = false;
});

it('carries meta untouched through a full run lifecycle', function () {
    defineWorkflow('meta-flow', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off')
        ->step(FlakyStep::class)
        ->step(FinalizeStep::class));

    // Steps ran, then the run parked at the gate.
    $run = AgentWorkflow::start('meta-flow', []);

    $run->mergeMeta(['slack_ts' => '123.456']);

    expect($run->meta)->toBe(['slack_ts' => '123.456']);

    // Resume into a failure at the flaky step.
    FlakyStep::$fail = true;

    try {
        $run->resume([]);
    } catch (RuntimeException) {
        // expected
    }

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->meta)->toBe(['slack_ts' => '123.456']);

    // Retry to completion.
    FlakyStep::$fail = false;

    $run = $run->retry();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->meta)->toBe(['slack_ts' => '123.456']);
});

it('survives sweep recovery', function () {
    $definition = defineWorkflow('meta-swept', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    // A run stranded by a dead worker: pending, stale, never dispatched.
    $run = WorkflowRun::create([
        'name' => 'meta-swept',
        'version' => $definition->hash(),
        'status' => RunStatus::Pending,
        'current_step' => 'PrepareStep',
        'state' => [],
        'meta' => ['ticket_ref' => 'T-99'],
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $this->artisan('agent-workflows:sweep')->assertSuccessful();

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and($run->meta)->toBe(['ticket_ref' => 'T-99']);
});

it('merges instead of clobbering', function () {
    defineWorkflow('meta-merge', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    $run = AgentWorkflow::start('meta-merge', []);

    $run->mergeMeta(['a' => 1]);
    $run->mergeMeta(['b' => 2]);
    $run = $run->mergeMeta(['a' => 3]);

    expect($run->meta)->toBe(['a' => 3, 'b' => 2]);
});
