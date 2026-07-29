<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\RefineStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

it('loops the evaluate step until the predicate is satisfied', function () {
    defineWorkflow('refine', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->evaluate(RefineStep::class, until: fn (WorkflowState $s) => $s->get('score') >= 7, maxIterations: 5)
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('refine', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['score'])->toBe(9) // 3 iterations x +3
        ->and($run->state['steps']['RefineStep']['iteration'])->toBe(3)
        ->and($run->state['steps']['RefineStep']['satisfied'])->toBeTrue()
        ->and($run->state['finalized'])->toBeTrue()
        // Every iteration is checkpointed as its own audit row.
        ->and($run->steps()->where('step_id', 'RefineStep')->count())->toBe(3);
});

it('stops at maxIterations when the predicate is never satisfied', function () {
    defineWorkflow('capped', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->evaluate(RefineStep::class, until: fn (WorkflowState $s) => $s->get('score') >= 100, maxIterations: 2)
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('capped', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['score'])->toBe(6)
        ->and($run->state['steps']['RefineStep']['iteration'])->toBe(2)
        ->and($run->state['steps']['RefineStep']['satisfied'])->toBeFalse()
        ->and($run->state['finalized'])->toBeTrue();
});

it('lets until-predicates address the loop output by class, like any other step', function () {
    SummarizeAgent::fake(['Draft one.', 'Final copy.']);

    defineWorkflow('by-class', fn (WorkflowDefinition $workflow) => $workflow
        ->evaluate(SummarizeAgent::class,
            prompt: 'Revise the copy.',
            until: fn (WorkflowState $s) => $s->output(SummarizeAgent::class)?->text() === 'Final copy.',
            maxIterations: 5));

    $run = AgentWorkflow::start('by-class', []);

    // The documented class-based addressing reaches the loop's own
    // checkpoints: the predicate matched on iteration 2 instead of
    // silently burning all five iterations against a mismatched id.
    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps']['SummarizeAgent']['iteration'])->toBe(2)
        ->and($run->state['steps']['SummarizeAgent']['satisfied'])->toBeTrue();
});
