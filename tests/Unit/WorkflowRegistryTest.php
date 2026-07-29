<?php

use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\TransformStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

it('treats re-registering an identical definition as a no-op', function () {
    $registry = new WorkflowRegistry;

    $registry->register((new WorkflowDefinition('flow'))->step(PrepareStep::class));
    $registry->register((new WorkflowDefinition('flow'))->step(PrepareStep::class));

    expect($registry->get('flow')->steps())->toHaveCount(1);
});

it('rejects a different definition under an existing name', function () {
    $registry = new WorkflowRegistry;

    $registry->register((new WorkflowDefinition('flow'))->step(PrepareStep::class));

    expect(fn () => $registry->register((new WorkflowDefinition('flow'))->step(TransformStep::class)))
        ->toThrow(InvalidArgumentException::class, 'already registered with a different definition');

    // The original registration is untouched.
    expect($registry->get('flow')->firstStep()->id)->toBe('PrepareStep');
});

it('allows replacement after an explicit forget', function () {
    $registry = new WorkflowRegistry;

    $registry->register((new WorkflowDefinition('flow'))->step(PrepareStep::class));
    $registry->forget('flow');
    $registry->register((new WorkflowDefinition('flow'))->step(TransformStep::class));

    expect($registry->get('flow')->firstStep()->id)->toBe('TransformStep');
});
