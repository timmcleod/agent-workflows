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

it('excludes labels from the definition hash', function () {
    $until = fn ($state) => true;

    $plain = (new WorkflowDefinition('labeled'))
        ->step(PrepareStep::class)
        ->when(fn ($state) => true, then: TransformStep::class)
        ->parallel([TransformStep::class, SummarizeAgent::class])
        ->evaluate(TransformStep::class, until: $until, as: 'loop')
        ->awaitHuman(reason: 'Sign-off')
        ->awaitEvent('payment.confirmed');

    $labeled = (new WorkflowDefinition('labeled'))
        ->step(PrepareStep::class, label: 'Getting ready')
        ->when(fn ($state) => true, then: TransformStep::class, label: 'Choosing a path')
        ->parallel([TransformStep::class, SummarizeAgent::class], label: 'Fanning out')
        ->evaluate(TransformStep::class, until: $until, as: 'loop', label: 'Polishing')
        ->awaitHuman(reason: 'Sign-off', label: 'Waiting on the boss')
        ->awaitEvent('payment.confirmed', label: 'Waiting on the wire');

    expect($labeled->hash())->toBe($plain->hash());
});

it('includes string prompts in the definition hash', function () {
    $one = (new WorkflowDefinition('p'))->step(SummarizeAgent::class, prompt: 'Summarize A.');
    $changed = (new WorkflowDefinition('p'))->step(SummarizeAgent::class, prompt: 'Summarize B.');

    expect($one->hash())->not->toBe($changed->hash());
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

it('rejects a duplicate explicit alias instead of silently renaming it', function () {
    expect(fn () => (new WorkflowDefinition('clash'))
        ->step(PrepareStep::class, as: 'review')
        ->step(TransformStep::class, as: 'review'))
        ->toThrow(InvalidArgumentException::class, 'must be unique');
});

it('rejects an explicit alias that collides with a derived id', function () {
    expect(fn () => (new WorkflowDefinition('clash'))
        ->step(PrepareStep::class)
        ->step(TransformStep::class, as: 'PrepareStep'))
        ->toThrow(InvalidArgumentException::class, 'must be unique');
});

it('still dedupes derived ids for repeated classes with a numeric suffix', function () {
    $definition = (new WorkflowDefinition('repeat'))
        ->step(TransformStep::class)
        ->step(TransformStep::class);

    expect(array_map(fn ($s) => $s->id, $definition->steps()))->toBe(['TransformStep', 'TransformStep:2']);
});

it('rejects step targets that are not classes at definition time', function () {
    expect(fn () => (new WorkflowDefinition('typo'))->step('App\Agents\Summarzie'))
        ->toThrow(InvalidArgumentException::class, 'is not a class');
});
