<?php

use Illuminate\Support\Facades\Event;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Events\WorkflowGroupSettled;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Runtime\GroupSettler;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

beforeEach(function () {
    FlakyStep::$fail = false;
});

it('settles a group once, when its last member finishes', function () {
    Event::fake([WorkflowGroupSettled::class]);

    defineWorkflow('grouped-gate', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off'));

    // Groups are global: two different workflows share one group.
    defineWorkflow('grouped-gate-b', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off'));

    $a = AgentWorkflow::start('grouped-gate', [], group: 'conversation:1');
    $b = AgentWorkflow::start('grouped-gate-b', [], group: 'conversation:1');
    $c = AgentWorkflow::start('grouped-gate', [], group: 'conversation:2');

    // One sibling finishing while another is active settles nothing.
    $a->resume([]);
    Event::assertNotDispatched(WorkflowGroupSettled::class);

    // The last finisher's settle delivers every terminal member — of this
    // group only.
    $b->resume([]);

    Event::assertDispatchedTimes(WorkflowGroupSettled::class, 1);
    Event::assertDispatched(WorkflowGroupSettled::class, function (WorkflowGroupSettled $event) use ($a, $b) {
        return $event->groupKey === 'conversation:1'
            && $event->runs->pluck('id')->sort()->values()->all()
                === collect([$a->id, $b->id])->sort()->values()->all();
    });

    expect($a->refresh()->settled_at)->not->toBeNull()
        ->and($b->refresh()->settled_at)->not->toBeNull()
        ->and($c->refresh()->settled_at)->toBeNull();
});

it('delivers nothing on a second settle attempt', function () {
    Event::fake([WorkflowGroupSettled::class]);

    defineWorkflow('grouped-simple', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    AgentWorkflow::start('grouped-simple', [], group: 'g1');

    Event::assertDispatchedTimes(WorkflowGroupSettled::class, 1);

    app(GroupSettler::class)->settle('g1');

    Event::assertDispatchedTimes(WorkflowGroupSettled::class, 1);
});

it('re-settles groups whose settle never fired, via the sweeper', function () {
    Event::fake([WorkflowGroupSettled::class]);

    $definition = defineWorkflow('grouped-orphan', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    // Simulate a settle that never ran (worker died, or a lifecycle
    // listener threw before settle() executed): terminal grouped runs,
    // unstamped.
    $make = fn () => WorkflowRun::create([
        'name' => 'grouped-orphan',
        'group_key' => 'conversation:7',
        'version' => $definition->hash(),
        'status' => RunStatus::Completed,
        'current_step' => 'PrepareStep',
        'state' => [],
        'finished_at' => now(),
    ]);

    $a = $make();
    $b = $make();

    $this->artisan('agent-workflows:sweep')->assertSuccessful();

    Event::assertDispatchedTimes(WorkflowGroupSettled::class, 1);
    Event::assertDispatched(WorkflowGroupSettled::class, fn (WorkflowGroupSettled $event) => $event->groupKey === 'conversation:7'
        && $event->runs->pluck('id')->sort()->values()->all()
            === collect([$a->id, $b->id])->sort()->values()->all());

    // A second sweep delivers nothing.
    $this->artisan('agent-workflows:sweep')->assertSuccessful();

    Event::assertDispatchedTimes(WorkflowGroupSettled::class, 1);
});

it('delivers a cancelled outcome for a run that already settled as failed', function () {
    Event::fake([WorkflowGroupSettled::class]);

    defineWorkflow('grouped-cancel', fn (WorkflowDefinition $workflow) => $workflow
        ->step(FlakyStep::class));

    FlakyStep::$fail = true;

    try {
        AgentWorkflow::start('grouped-cancel', [], group: 'conversation:3');
    } catch (RuntimeException) {
        // expected
    }

    // Sole member failed => the group settled with the failed outcome.
    Event::assertDispatchedTimes(WorkflowGroupSettled::class, 1);

    $run = WorkflowRun::sole();

    // Cancelling changes the outcome; the next settle delivers it.
    $run->cancel();

    Event::assertDispatchedTimes(WorkflowGroupSettled::class, 2);

    $settles = Event::dispatched(WorkflowGroupSettled::class);

    expect($settles[1][0]->runs->sole()->status->value)->toBe('cancelled');
});

it('settles again for later joiners, and delivers a retried run exactly once more', function () {
    Event::fake([WorkflowGroupSettled::class]);

    defineWorkflow('grouped-ok', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));
    defineWorkflow('grouped-flaky', fn (WorkflowDefinition $workflow) => $workflow
        ->step(FlakyStep::class));

    $ok = AgentWorkflow::start('grouped-ok', [], group: 'conversation:9');

    FlakyStep::$fail = true;

    try {
        AgentWorkflow::start('grouped-flaky', [], group: 'conversation:9');
    } catch (RuntimeException) {
        // expected
    }

    $flaky = WorkflowRun::query()->where('name', 'grouped-flaky')->sole();

    FlakyStep::$fail = false;

    // Retry clears settled_at, so the retried run's new outcome is
    // delivered in the following settle.
    $flaky->retry();

    $settles = Event::dispatched(WorkflowGroupSettled::class);

    expect($settles)->toHaveCount(3)
        ->and($settles[0][0]->runs->pluck('id')->all())->toBe([$ok->id])       // ok completed, sole member
        ->and($settles[1][0]->runs->pluck('id')->all())->toBe([$flaky->id])    // flaky failed (failed is terminal for settling)
        ->and($settles[2][0]->runs->pluck('id')->all())->toBe([$flaky->id])    // retried outcome, delivered once more
        ->and($flaky->refresh()->settled_at)->not->toBeNull();
});
