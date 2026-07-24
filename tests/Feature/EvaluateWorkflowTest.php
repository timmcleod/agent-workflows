<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\RefineStep;
use TimMcLeod\AgentWorkflows\WorkflowState;

it('loops the evaluate step until the predicate is satisfied', function () {
    AgentWorkflow::define('refine')
        ->start(PrepareStep::class)
        ->evaluate(RefineStep::class, until: fn (WorkflowState $s) => $s->get('score') >= 7, maxIterations: 5)
        ->then(FinalizeStep::class);

    $run = AgentWorkflow::start('refine', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['score'])->toBe(9) // 3 iterations x +3
        ->and($run->state['steps']['evaluate:RefineStep']['iteration'])->toBe(3)
        ->and($run->state['steps']['evaluate:RefineStep']['satisfied'])->toBeTrue()
        ->and($run->state['finalized'])->toBeTrue()
        // Every iteration is checkpointed as its own audit row.
        ->and($run->steps()->where('step_id', 'evaluate:RefineStep')->count())->toBe(3);
});

it('stops at maxIterations when the predicate is never satisfied', function () {
    AgentWorkflow::define('capped')
        ->start(PrepareStep::class)
        ->evaluate(RefineStep::class, until: fn (WorkflowState $s) => $s->get('score') >= 100, maxIterations: 2)
        ->then(FinalizeStep::class);

    $run = AgentWorkflow::start('capped', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['score'])->toBe(6)
        ->and($run->state['steps']['evaluate:RefineStep']['iteration'])->toBe(2)
        ->and($run->state['steps']['evaluate:RefineStep']['satisfied'])->toBeFalse()
        ->and($run->state['finalized'])->toBeTrue();
});
