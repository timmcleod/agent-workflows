<?php

use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\TransformStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

it('classifies agent classes and invokables into step types', function () {
    $definition = (new WorkflowDefinition('typed'))
        ->start(SummarizeAgent::class)
        ->then(PrepareStep::class);

    expect($definition->steps()[0]->type)->toBe(StepType::Agent)
        ->and($definition->steps()[1]->type)->toBe(StepType::Callback);
});

it('walks steps sequentially via after()', function () {
    $definition = (new WorkflowDefinition('walk'))
        ->start(PrepareStep::class)
        ->then(TransformStep::class);

    expect($definition->firstStep()->id)->toBe('PrepareStep')
        ->and($definition->after('PrepareStep')?->id)->toBe('TransformStep')
        ->and($definition->after('TransformStep'))->toBeNull()
        ->and(fn () => $definition->after('Nope'))->toThrow(WorkflowException::class);
});

it('changes its hash when the definition changes', function () {
    $one = (new WorkflowDefinition('h'))->start(PrepareStep::class);
    $same = (new WorkflowDefinition('h'))->start(PrepareStep::class);
    $different = (new WorkflowDefinition('h'))->start(PrepareStep::class)->then(TransformStep::class);

    expect($one->hash())->toBe($same->hash())->not->toBe($different->hash());
});

it('supports explicit step aliases', function () {
    $definition = (new WorkflowDefinition('aliased'))
        ->start(TransformStep::class, as: 'double')
        ->then(TransformStep::class, as: 'double-again');

    expect(array_map(fn ($s) => $s->id, $definition->steps()))->toBe(['double', 'double-again']);
});
