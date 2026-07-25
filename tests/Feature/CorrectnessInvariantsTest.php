<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Jobs\WorkflowStepJob;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Runtime\Progression;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\CounterBoomStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;
use TimMcLeod\AgentWorkflows\WorkflowState;

beforeEach(function () {
    CounterBoomStep::$count = 0;
    CounterBoomStep::$fail = false;
    FlakyStep::$fail = false;
});

it('no-ops a duplicate delivery of an already-completed step', function () {
    defineWorkflow('dup-completed', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'hold'));

    $run = AgentWorkflow::start('dup-completed', []);

    expect($run->status)->toBe(RunStatus::AwaitingHuman)
        ->and($run->state['sequence'])->toBe(['prepare']);

    // The queue redelivers the first step's job after the cursor moved on.
    (new WorkflowStepJob($run->id, 'PrepareStep'))->handle(app(WorkflowRegistry::class));

    $run->refresh();

    expect($run->steps()->where('step_id', 'PrepareStep')->count())->toBe(1)
        ->and($run->state['sequence'])->toBe(['prepare'])
        ->and($run->status)->toBe(RunStatus::AwaitingHuman);
});

it('no-ops a duplicate delivery while the step is in flight', function () {
    $definition = defineWorkflow('dup-inflight', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    $run = WorkflowRun::create([
        'name' => 'dup-inflight',
        'version' => $definition->hash(),
        'status' => RunStatus::Running,
        'current_step' => 'PrepareStep',
        'state' => [],
    ]);

    // Another worker is mid-flight on this step.
    $run->steps()->create([
        'step_id' => 'PrepareStep',
        'type' => StepType::Callback,
        'status' => StepStatus::Running,
        'attempt' => 1,
        'started_at' => now(),
    ]);

    (new WorkflowStepJob($run->id, 'PrepareStep'))->handle(app(WorkflowRegistry::class));

    $run->refresh();

    expect($run->steps()->where('step_id', 'PrepareStep')->count())->toBe(1)
        ->and($run->state)->toBe([])
        ->and($run->status)->toBe(RunStatus::Running);
});

it('does not dispatch the next step when the checkpoint transaction rolls back', function () {
    $definition = defineWorkflow('rollback', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(CounterBoomStep::class));

    $run = WorkflowRun::create([
        'name' => 'rollback',
        'version' => $definition->hash(),
        'status' => RunStatus::Running,
        'current_step' => 'PrepareStep',
        'state' => [],
    ]);

    $stepRow = $run->steps()->create([
        'step_id' => 'PrepareStep',
        'type' => StepType::Callback,
        'status' => StepStatus::Running,
        'attempt' => 1,
        'started_at' => now(),
    ]);

    DB::beginTransaction();

    app(Progression::class)->complete(
        $run, $definition, $definition->findStep('PrepareStep'), $stepRow, WorkflowState::make(['prepared' => true]),
    );

    DB::rollBack();

    // The next step would have run inline on the sync queue and bumped the
    // counter — a non-transactional side effect a rollback cannot hide.
    expect(CounterBoomStep::$count)->toBe(0)
        ->and($run->refresh()->current_step)->toBe('PrepareStep')
        ->and($run->state)->toBe([]);
});

it('re-runs a crashed step exactly once on retry (at-least-once semantics)', function () {
    CounterBoomStep::$fail = true;

    defineWorkflow('crash-retry', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(CounterBoomStep::class)
        ->step(FinalizeStep::class));

    try {
        AgentWorkflow::start('crash-retry', []);
    } catch (RuntimeException) {
        // the crash surfaces on the sync queue
    }

    $run = WorkflowRun::sole();

    // The side effect happened even though the checkpoint did not commit —
    // this is the documented at-least-once contract for step bodies.
    expect(CounterBoomStep::$count)->toBe(1)
        ->and($run->status)->toBe(RunStatus::Failed)
        ->and($run->failed_step)->toBe('CounterBoomStep');

    CounterBoomStep::$fail = false;

    $run = $run->retry();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(CounterBoomStep::$count)->toBe(2)
        ->and($run->steps()->where('step_id', 'PrepareStep')->count())->toBe(1)
        ->and($run->steps()->where('step_id', 'CounterBoomStep')->count())->toBe(2);
});

it('allows exactly one of two concurrent resumes', function () {
    defineWorkflow('double-resume', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off')
        ->step(FinalizeStep::class));

    $started = AgentWorkflow::start('double-resume', []);

    // Two stale copies of the run, as held by two racing HTTP requests.
    $first = WorkflowRun::findOrFail($started->id);
    $second = WorkflowRun::findOrFail($started->id);

    $first = $first->resume(['ok' => true]);

    expect($first->status)->toBe(RunStatus::Completed)
        ->and(fn () => $second->resume(['ok' => true]))->toThrow(WorkflowException::class);

    expect($first->refresh()->steps()->where('step_id', 'FinalizeStep')->count())->toBe(1)
        ->and($first->interrupts()->whereNotNull('resolved_at')->count())->toBe(1);
});

it('allows exactly one of two concurrent retries', function () {
    FlakyStep::$fail = true;

    defineWorkflow('double-retry', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(FlakyStep::class)
        ->step(FinalizeStep::class));

    try {
        AgentWorkflow::start('double-retry', []);
    } catch (RuntimeException) {
        // expected
    }

    FlakyStep::$fail = false;

    $first = WorkflowRun::sole();
    $second = WorkflowRun::findOrFail($first->id);

    $first = $first->retry();

    expect($first->status)->toBe(RunStatus::Completed)
        ->and(fn () => $second->retry())->toThrow(WorkflowException::class);

    expect($first->refresh()->steps()->where('step_id', 'FlakyStep')->count())->toBe(2)
        ->and($first->steps()->where('step_id', 'FinalizeStep')->count())->toBe(1);
});

it('allows exactly one of two concurrent event deliveries', function () {
    defineWorkflow('double-event', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitEvent('payment.confirmed')
        ->step(FinalizeStep::class));

    $started = AgentWorkflow::start('double-event', []);

    $first = WorkflowRun::findOrFail($started->id);
    $second = WorkflowRun::findOrFail($started->id);

    $first = $first->deliverEvent('payment.confirmed', ['amount' => 1]);

    expect($first->status)->toBe(RunStatus::Completed)
        ->and(fn () => $second->deliverEvent('payment.confirmed', ['amount' => 1]))->toThrow(WorkflowException::class);

    expect($first->refresh()->steps()->where('step_id', 'FinalizeStep')->count())->toBe(1);
});

it('enforces the attempt uniqueness barrier at the schema level', function () {
    $definition = defineWorkflow('barrier', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    $run = WorkflowRun::create([
        'name' => 'barrier',
        'version' => $definition->hash(),
        'status' => RunStatus::Running,
        'current_step' => 'PrepareStep',
        'state' => [],
    ]);

    $attributes = [
        'step_id' => 'PrepareStep',
        'type' => StepType::Callback,
        'status' => StepStatus::Running,
        'attempt' => 1,
    ];

    $run->steps()->create($attributes);

    expect(fn () => $run->steps()->create($attributes))
        ->toThrow(UniqueConstraintViolationException::class);
});
