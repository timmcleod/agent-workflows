<?php

use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\TransformStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Workflows\ConventionalPromptWorkflow;
use TimMcLeod\AgentWorkflows\Workflow;
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
    // Positional: the prompt is step()'s second argument as of v0.15.
    $one = (new WorkflowDefinition('p'))->step(SummarizeAgent::class, 'Summarize A.');
    $changed = (new WorkflowDefinition('p'))->step(SummarizeAgent::class, 'Summarize B.');

    expect($one->hash())->not->toBe($changed->hash());
});

it('accepts the prompt as the second positional argument', function () {
    $definition = (new WorkflowDefinition('positional'))
        ->step(SummarizeAgent::class, 'A literal prompt.')
        ->step(SummarizeAgent::class, fn ($state) => 'From a closure.', as: 'second');

    expect($definition->findStep('SummarizeAgent')->prompt)->toBe('A literal prompt.')
        ->and($definition->findStep('second')->prompt)->toBeInstanceOf(Closure::class);
});

it('binds conventional prompt methods and fingerprints them as closures', function () {
    $definition = (new ConventionalPromptWorkflow)->definition();

    // Bound method: a closure at runtime, the opaque closure marker in the
    // hash, so migrating from explicit prompt: $this->x(...) wiring to the
    // convention never changes a definition's fingerprint.
    expect($definition->findStep('SummarizeAgent')->prompt)->toBeInstanceOf(Closure::class)
        ->and($definition->findStep('SummarizeAgent')->fingerprint()['prompt'])->toBe('(closure)')
        // Explicit prompts always win over a matching method.
        ->and($definition->findStep('BullCaseAgent')->prompt)->toBe('Explicit wins.')
        // Branch targets and parallel branches bind by their own ids.
        ->and($definition->findStep('DeployAgent')->prompt)->toBeInstanceOf(Closure::class)
        ->and($definition->findStep('BearCaseAgent')->prompt)->toBeInstanceOf(Closure::class);
});

it('trips strict drift when a conventional prompt method is added', function () {
    $without = new class extends Workflow
    {
        public function name(): string
        {
            return 'drifting';
        }

        public function build(WorkflowDefinition $workflow): WorkflowDefinition
        {
            return $workflow->step(SummarizeAgent::class);
        }
    };

    $with = new class extends Workflow
    {
        public function name(): string
        {
            return 'drifting';
        }

        public function build(WorkflowDefinition $workflow): WorkflowDefinition
        {
            return $workflow->step(SummarizeAgent::class);
        }

        protected function summarizeAgentPrompt($state): string
        {
            return 'Now bound.';
        }
    };

    // Adding the method turns an absent prompt into '(closure)': a new hash,
    // so in-flight runs notice, exactly like adding an explicit prompt would.
    expect($without->definition()->hash())->not->toBe($with->definition()->hash());
});

it('rebinds the conventional method when a step alias changes', function () {
    $workflow = new class extends Workflow
    {
        public string $alias = 'risk';

        public function name(): string
        {
            return 'aliased';
        }

        public function build(WorkflowDefinition $workflow): WorkflowDefinition
        {
            return $workflow->step(SummarizeAgent::class, as: $this->alias);
        }

        protected function riskPrompt($state): string
        {
            return 'Assess risk.';
        }
    };

    expect($workflow->definition()->findStep('risk')->prompt)->toBeInstanceOf(Closure::class);

    // Renaming the alias silently unbinds the method: a rename is now a
    // behavior change, not just an id change.
    $workflow->alias = 'risky';

    expect($workflow->definition()->findStep('risky')->prompt)->toBeNull();
});

it('never matches conventional methods for ids that cannot be method names', function () {
    $workflow = new class extends Workflow
    {
        public function name(): string
        {
            return 'colon-ids';
        }

        public function build(WorkflowDefinition $workflow): WorkflowDefinition
        {
            // The second derived id is SummarizeAgent:2; camel keeps the
            // colon, so method_exists never matches and never errors.
            return $workflow
                ->step(SummarizeAgent::class)
                ->step(SummarizeAgent::class);
        }

        protected function summarizeAgentPrompt($state): string
        {
            return 'First only.';
        }
    };

    $definition = $workflow->definition();

    expect($definition->findStep('SummarizeAgent')->prompt)->toBeInstanceOf(Closure::class)
        ->and($definition->findStep('SummarizeAgent:2')->prompt)->toBeNull();
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
