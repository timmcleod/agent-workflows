<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\TransformStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

it('runs sequential steps in order and completes the run', function () {
    defineWorkflow('sequential', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(TransformStep::class)
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('sequential', ['value' => 3]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->state['sequence'])->toBe(['prepare', 'transform', 'finalize'])
        ->and($run->state['value'])->toBe(6)
        ->and($run->state['prepared'])->toBeTrue()
        ->and($run->state['finalized'])->toBeTrue();
});

it('checkpoints state into an audit row after every step', function () {
    defineWorkflow('audited', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(TransformStep::class));

    $run = AgentWorkflow::start('audited', ['value' => 5]);

    $steps = $run->steps()->orderBy('id')->get();

    expect($steps)->toHaveCount(2)
        ->and($steps->pluck('step_id')->all())->toBe(['PrepareStep', 'TransformStep'])
        ->and($steps->pluck('status')->unique()->all())->toBe([StepStatus::Completed])
        ->and($steps[0]->output_state['prepared'])->toBeTrue()
        ->and($steps[0]->output_state)->not->toHaveKey('value_doubled')
        ->and($steps[1]->output_state['value'])->toBe(10)
        ->and($steps[0]->input_state_hash)->not->toBeNull()
        ->and($steps[0]->finished_at)->not->toBeNull();

    // The second step's input hash must equal the hash of the first step's output.
    expect($steps[1]->input_state_hash)
        ->toBe(WorkflowState::make($steps[0]->output_state)->hash());
});

it('gives duplicate step classes distinct step ids', function () {
    $definition = defineWorkflow('doubled', fn (WorkflowDefinition $workflow) => $workflow
        ->step(TransformStep::class)
        ->step(TransformStep::class));

    expect(array_map(fn ($s) => $s->id, $definition->steps()))
        ->toBe(['TransformStep', 'TransformStep:2']);

    $run = AgentWorkflow::start('doubled', ['value' => 1]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['value'])->toBe(4);
});
