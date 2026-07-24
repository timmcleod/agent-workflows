<?php

use TimMcLeod\AgentWorkflows\WorkflowState;

it('reads and writes with dot notation', function () {
    $state = WorkflowState::make(['a' => ['b' => 1]]);

    expect($state->get('a.b'))->toBe(1)
        ->and($state->get('missing', 'fallback'))->toBe('fallback')
        ->and($state->has('a.b'))->toBeTrue();

    $state->set('a.c', 2)->merge(['d' => 3]);

    expect($state->all())->toBe(['a' => ['b' => 1, 'c' => 2], 'd' => 3]);

    $state->forget('a.b');

    expect($state->has('a.b'))->toBeFalse();
});

it('hashes identical contents identically', function () {
    expect(WorkflowState::make(['x' => 1])->hash())
        ->toBe(WorkflowState::make(['x' => 1])->hash())
        ->not->toBe(WorkflowState::make(['x' => 2])->hash());
});
