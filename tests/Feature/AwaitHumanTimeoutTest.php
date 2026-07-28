<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

it('records the deadline on the interrupt when the gate declares a timeout', function () {
    defineWorkflow('deadline', fn (WorkflowDefinition $workflow) => $workflow
        ->awaitHuman(reason: 'Sign off', timeout: 3600, as: 'gate'));

    $run = AgentWorkflow::start('deadline', []);
    $interrupt = $run->interrupts()->sole();

    expect($interrupt->timeout_at)->not->toBeNull()
        ->and(round(now()->diffInSeconds($interrupt->timeout_at)))->toEqualWithDelta(3600, 5);
});

it('leaves gates without a timeout alone forever', function () {
    defineWorkflow('patient', fn (WorkflowDefinition $workflow) => $workflow
        ->awaitHuman(reason: 'Sign off'));

    $run = AgentWorkflow::start('patient', []);

    expect($run->interrupts()->sole()->timeout_at)->toBeNull();

    $this->travel(300)->days();
    $this->artisan('agent-workflows:sweep');

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingHuman);
});

it('does not act before the deadline', function () {
    defineWorkflow('early', fn (WorkflowDefinition $workflow) => $workflow
        ->awaitHuman(reason: 'Sign off', timeout: 3600));

    $run = AgentWorkflow::start('early', []);

    $this->travel(30)->minutes();
    $this->artisan('agent-workflows:sweep');

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingHuman);
});

it('resumes a timed-out gate with its timeoutResponse and continues the run', function () {
    defineWorkflow('auto-reject', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign off', schema: [
            'approved' => 'required|boolean',
            'notes' => 'nullable|string',
        ], timeout: 3600, timeoutResponse: ['approved' => false, 'notes' => 'Auto-rejected: sign-off timed out.'])
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('auto-reject', []);

    expect($run->status)->toBe(RunStatus::AwaitingHuman);

    $this->travel(2)->hours();
    $this->artisan('agent-workflows:sweep');

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['approved'])->toBeFalse()
        ->and($run->state['notes'])->toBe('Auto-rejected: sign-off timed out.')
        ->and($run->state['finalized'])->toBeTrue()
        ->and($run->interrupts()->sole()->resolved_at)->not->toBeNull();
});

it('fails a timed-out gate without a timeoutResponse, and retry re-arms it', function () {
    defineWorkflow('strict-gate', fn (WorkflowDefinition $workflow) => $workflow
        ->awaitHuman(reason: 'Sign off', timeout: 3600, as: 'gate')
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('strict-gate', []);

    $this->travel(2)->hours();
    $this->artisan('agent-workflows:sweep');

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failed_step)->toBe('gate')
        ->and($run->failure_reason)->toContain('Timed out waiting for a human')
        // The wait is deliberately left open so a retry re-parks on it
        // instead of sailing through a "resolved" gate.
        ->and($run->interrupts()->sole()->resolved_at)->toBeNull();

    $run = $run->retry();

    expect($run->status)->toBe(RunStatus::AwaitingHuman);

    // The same interrupt, re-armed with a fresh deadline.
    $interrupt = $run->interrupts()->sole();

    expect(round(now()->diffInSeconds($interrupt->timeout_at)))->toEqualWithDelta(3600, 5);

    // And a human can still resolve it normally.
    $run = $run->resume([]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['finalized'])->toBeTrue();
});

it('sweeping twice does not double-act on the same timeout', function () {
    defineWorkflow('idempotent-gate', fn (WorkflowDefinition $workflow) => $workflow
        ->awaitHuman(reason: 'Sign off', timeout: 3600));

    $run = AgentWorkflow::start('idempotent-gate', []);

    $this->travel(2)->hours();
    $this->artisan('agent-workflows:sweep');
    $this->artisan('agent-workflows:sweep');

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->interrupts()->count())->toBe(1);
});

it('accepts a DateInterval timeout', function () {
    defineWorkflow('interval', fn (WorkflowDefinition $workflow) => $workflow
        ->awaitHuman(reason: 'Sign off', timeout: new DateInterval('P3D')));

    $run = AgentWorkflow::start('interval', []);

    expect(round(now()->diffInSeconds($run->interrupts()->sole()->timeout_at)))
        ->toEqualWithDelta(3 * 86400, 5);
});

it('rejects a timeoutResponse without a timeout and non-positive timeouts', function () {
    expect(fn () => (new WorkflowDefinition('bad'))->awaitHuman(timeoutResponse: ['approved' => false]))
        ->toThrow(InvalidArgumentException::class, 'requires a timeout')
        ->and(fn () => (new WorkflowDefinition('bad2'))->awaitHuman(timeout: 0))
        ->toThrow(InvalidArgumentException::class, 'at least one second');
});

it('includes the timeout in the definition hash', function () {
    $without = (new WorkflowDefinition('h'))->awaitHuman(reason: 'Sign off');
    $with = (new WorkflowDefinition('h'))->awaitHuman(reason: 'Sign off', timeout: 3600);

    expect($without->hash())->not->toBe($with->hash());
});
