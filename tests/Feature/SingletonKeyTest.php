<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Events\WorkflowStarted;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

beforeEach(function () {
    FlakyStep::$fail = false;

    defineWorkflow('keyed-gate', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off'));
});

it('returns the existing active run instead of starting a duplicate', function () {
    $first = AgentWorkflow::start('keyed-gate', [], key: 'ticket:1');

    expect($first->status)->toBe(RunStatus::AwaitingHuman)
        ->and($first->wasRecentlyCreated)->toBeTrue()
        ->and($first->key)->toBe('ticket:1')
        ->and($first->active_key)->toBe('ticket:1');

    $second = AgentWorkflow::start('keyed-gate', [], key: 'ticket:1');

    expect($second->id)->toBe($first->id)
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and(WorkflowRun::query()->count())->toBe(1);
});

it('makes the idempotent return side-effect free', function () {
    AgentWorkflow::start('keyed-gate', [], key: 'ticket:1');

    Event::fake([WorkflowStarted::class]);
    Bus::fake();

    $again = AgentWorkflow::start('keyed-gate', [], key: 'ticket:1');

    expect($again->wasRecentlyCreated)->toBeFalse();

    Event::assertNotDispatched(WorkflowStarted::class);
    Bus::assertNothingDispatched();
});

it('returns the existing run inside a caller transaction', function () {
    $first = AgentWorkflow::start('keyed-gate', [], key: 'ticket:1');

    // The insert's unique violation must not poison the caller's
    // transaction (Postgres aborts on statement errors without the
    // savepoint around the insert).
    $second = DB::transaction(
        fn () => AgentWorkflow::start('keyed-gate', [], key: 'ticket:1'),
    );

    expect($second->id)->toBe($first->id)
        ->and($second->wasRecentlyCreated)->toBeFalse();
});

it('adopts the requested group on an idempotent return only when the run has none', function () {
    $first = AgentWorkflow::start('keyed-gate', [], key: 'ticket:1');

    expect($first->group_key)->toBeNull();

    $adopted = AgentWorkflow::start('keyed-gate', [], key: 'ticket:1', group: 'conversation:1');

    expect($adopted->id)->toBe($first->id)
        ->and($adopted->group_key)->toBe('conversation:1');

    // An established group is never silently rewritten.
    $unchanged = AgentWorkflow::start('keyed-gate', [], key: 'ticket:1', group: 'conversation:2');

    expect($unchanged->group_key)->toBe('conversation:1');
});

it('scopes keys per workflow name', function () {
    defineWorkflow('keyed-gate-b', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off'));

    $a = AgentWorkflow::start('keyed-gate', [], key: 'ticket:1');
    $b = AgentWorkflow::start('keyed-gate-b', [], key: 'ticket:1');

    expect($b->id)->not->toBe($a->id)
        ->and($b->wasRecentlyCreated)->toBeTrue();
});

it('frees the key on completion so a new start creates a fresh run', function () {
    defineWorkflow('keyed-complete', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    $first = AgentWorkflow::start('keyed-complete', [], key: 'ticket:9');

    expect($first->status)->toBe(RunStatus::Completed)
        ->and($first->key)->toBe('ticket:9')
        ->and($first->active_key)->toBeNull();

    $second = AgentWorkflow::start('keyed-complete', [], key: 'ticket:9');

    expect($second->wasRecentlyCreated)->toBeTrue()
        ->and($second->id)->not->toBe($first->id);
});

it('frees the key on cancellation', function () {
    $run = AgentWorkflow::start('keyed-gate', [], key: 'ticket:2')->cancel();

    expect($run->refresh()->active_key)->toBeNull();

    expect(AgentWorkflow::start('keyed-gate', [], key: 'ticket:2')->wasRecentlyCreated)->toBeTrue();
});

it('re-claims the key on retry, or throws when another run now holds it', function () {
    FlakyStep::$fail = true;

    defineWorkflow('keyed-retry', fn (WorkflowDefinition $workflow) => $workflow
        ->step(FlakyStep::class)
        ->awaitHuman(reason: 'Sign-off'));

    try {
        AgentWorkflow::start('keyed-retry', [], key: 'k1');
        $this->fail('The flaky step should have thrown.');
    } catch (RuntimeException) {
        // expected
    }

    $failed = WorkflowRun::sole();

    expect($failed->status)->toBe(RunStatus::Failed)
        ->and($failed->active_key)->toBeNull();

    FlakyStep::$fail = false;

    // A second run claims the key while the first sits failed.
    $second = AgentWorkflow::start('keyed-retry', [], key: 'k1');

    expect($second->wasRecentlyCreated)->toBeTrue()
        ->and($second->status)->toBe(RunStatus::AwaitingHuman);

    expect(fn () => $failed->retry())->toThrow(WorkflowException::class, 'holds key');

    // Once the second run completes, the retry re-claims cleanly.
    $second->resume([]);

    $retried = $failed->retry();

    expect($retried->status)->toBe(RunStatus::AwaitingHuman)
        ->and($retried->active_key)->toBe('k1');
});
