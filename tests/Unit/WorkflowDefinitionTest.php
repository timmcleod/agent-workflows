<?php

use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\TransformStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

it('classifies agent classes and invokables into step types', function () {
    $definition = (new WorkflowDefinition('typed'))
        ->step(SummarizeAgent::class)
        ->step(PrepareStep::class);

    expect($definition->steps()[0]->type)->toBe(StepType::Agent)
        ->and($definition->steps()[1]->type)->toBe(StepType::Callback);
});

it('walks steps sequentially via after()', function () {
    $definition = (new WorkflowDefinition('walk'))
        ->step(PrepareStep::class)
        ->step(TransformStep::class);

    expect($definition->firstStep()->id)->toBe('PrepareStep')
        ->and($definition->after('PrepareStep')?->id)->toBe('TransformStep')
        ->and($definition->after('TransformStep'))->toBeNull()
        ->and(fn () => $definition->after('Nope'))->toThrow(WorkflowException::class);
});

it('changes its hash when the definition changes', function () {
    $one = (new WorkflowDefinition('h'))->step(PrepareStep::class);
    $same = (new WorkflowDefinition('h'))->step(PrepareStep::class);
    $different = (new WorkflowDefinition('h'))->step(PrepareStep::class)->step(TransformStep::class);

    expect($one->hash())->toBe($same->hash())->not->toBe($different->hash());
});

it('supports explicit step aliases', function () {
    $definition = (new WorkflowDefinition('aliased'))
        ->step(TransformStep::class, as: 'double')
        ->step(TransformStep::class, as: 'double-again');

    expect(array_map(fn ($s) => $s->id, $definition->steps()))->toBe(['double', 'double-again']);
});
