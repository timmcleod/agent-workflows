<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\TransformStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

it('reports the labeled step a run is parked on', function () {
    defineWorkflow('progressive', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class, label: 'Reading the full message thread')
        ->awaitHuman(reason: 'Sign-off required')
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('progressive', []);

    expect($run->progress())->toBe([
        'step' => 'await-human:2',
        'label' => 'Sign-off required',
        'index' => 2,
        'total' => 3,
        'status' => 'awaiting_human',
    ]);
});

it('resolves a cursor inside a condition branch to the owning top-level step', function () {
    $definition = defineWorkflow('branchy', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->when(fn ($state) => true, then: TransformStep::class, else: FinalizeStep::class)
        ->step(FinalizeStep::class, as: 'wrap'));

    $run = WorkflowRun::create([
        'name' => 'branchy',
        'version' => $definition->hash(),
        'status' => RunStatus::Running,
        'current_step' => 'TransformStep',
        'state' => [],
    ]);

    expect($run->progress())->toBe([
        'step' => 'when:2',
        'label' => 'Evaluating a condition',
        'index' => 2,
        'total' => 3,
        'status' => 'running',
    ]);
});

it('humanizes unlabeled class steps and never inflates the total for loops', function () {
    $definition = defineWorkflow('humanized', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->evaluate(TransformStep::class, as: 'revise',
            until: fn ($state) => $state->get('value', 0) >= 8)
        ->step(FinalizeStep::class));

    $run = WorkflowRun::create([
        'name' => 'humanized',
        'version' => $definition->hash(),
        'status' => RunStatus::Running,
        'current_step' => 'PrepareStep',
        'state' => [],
    ]);

    expect($run->progress()['label'])->toBe('Prepare step')
        ->and($run->progress()['total'])->toBe(3);

    $run->update(['current_step' => 'revise']);

    expect($run->refresh()->progress())->toBe([
        'step' => 'revise',
        'label' => 'Revise',
        'index' => 2,
        'total' => 3,
        'status' => 'running',
    ]);
});

it('uses purpose-built defaults for structural steps instead of engine ids', function () {
    $definition = defineWorkflow('structural', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([TransformStep::class, FinalizeStep::class])
        ->awaitHuman()
        ->awaitEvent('payment.confirmed'));

    [$prepare, $parallel, $gate, $event] = $definition->steps();

    expect($parallel->displayLabel())->toBe('Running parallel branches')
        ->and($gate->displayLabel())->toBe('Waiting for a person')
        ->and($event->displayLabel())->toBe('Waiting for an event');
});

it('degrades to the raw step id when the definition drifted or is unregistered', function () {
    $definition = defineWorkflow('drifting', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    $run = WorkflowRun::create([
        'name' => 'drifting',
        'version' => $definition->hash(),
        'status' => RunStatus::Running,
        'current_step' => 'GhostStep',
        'state' => [],
    ]);

    // The cursor names a step this definition no longer has.
    expect($run->progress())->toBe([
        'step' => 'GhostStep',
        'label' => 'GhostStep',
        'index' => 0,
        'total' => 0,
        'status' => 'running',
    ]);

    // The workflow is not registered in this process at all.
    app(WorkflowRegistry::class)->forget('drifting');

    expect($run->progress()['label'])->toBe('GhostStep');
});
