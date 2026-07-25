<?php

use Illuminate\Support\Facades\DB;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Events\WorkflowCancelled;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Runtime\Progression;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\CounterBoomStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

beforeEach(function () {
    CounterBoomStep::$count = 0;
    CounterBoomStep::$fail = false;
});

function strandRun(string $name, ?string $inFlightStep = null): WorkflowRun
{
    $definition = defineWorkflow($name, fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(FinalizeStep::class));

    $run = WorkflowRun::create([
        'name' => $name,
        'version' => $definition->hash(),
        'status' => RunStatus::Running,
        'current_step' => 'PrepareStep',
        'state' => [],
    ]);

    if ($inFlightStep !== null) {
        $run->steps()->create([
            'step_id' => $inFlightStep,
            'type' => StepType::Callback,
            'status' => StepStatus::Running,
            'attempt' => 1,
            'started_at' => now()->subHour(),
        ]);
    }

    // Backdate updated_at past the staleness threshold (raw update — the
    // model would refresh the timestamp).
    DB::table($run->getTable())->where('id', $run->id)->update(['updated_at' => now()->subHour()]);

    return $run->refresh();
}

it('sweeps a run stranded mid-step back to completion', function () {
    $run = strandRun('stranded-midstep', inFlightStep: 'PrepareStep');

    $this->artisan('agent-workflows:sweep')->assertSuccessful();

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['finalized'])->toBeTrue()
        // The stale attempt was superseded; the sweep's attempt completed.
        ->and($run->steps()->where('step_id', 'PrepareStep')->orderBy('id')->pluck('status')->all())
        ->toBe([StepStatus::Failed, StepStatus::Completed]);
});

it('sweeps a run whose next step was never dispatched', function () {
    $run = strandRun('stranded-undelivered');

    $this->artisan('agent-workflows:sweep')->assertSuccessful();

    expect($run->refresh()->status)->toBe(RunStatus::Completed);
});

it('leaves genuinely active runs alone', function () {
    $definition = defineWorkflow('active', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    $run = WorkflowRun::create([
        'name' => 'active',
        'version' => $definition->hash(),
        'status' => RunStatus::Running,
        'current_step' => 'PrepareStep',
        'state' => [],
    ]);

    // Recently updated: not stale, must not be touched.
    $this->artisan('agent-workflows:sweep')->assertSuccessful();

    expect($run->refresh()->status)->toBe(RunStatus::Running)
        ->and($run->steps()->count())->toBe(0);
});

it('marks stale runs failed when the sweep action is fail', function () {
    config()->set('agent-workflows.sweep.action', 'fail');

    $run = strandRun('stranded-fail-mode', inFlightStep: 'PrepareStep');

    $this->artisan('agent-workflows:sweep')->assertSuccessful();

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failure_reason)->toContain('swept')
        ->and($run->failed_step)->toBe('PrepareStep');

    // And the standard recovery path still works from there.
    expect($run->retry()->status)->toBe(RunStatus::Completed);
});

it('cancels a parked run and resolves its interrupt', function () {
    $fake = AgentWorkflow::fake();

    defineWorkflow('cancel-parked', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off')
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('cancel-parked', []);

    $run = $run->cancel();

    expect($run->status)->toBe(RunStatus::Cancelled)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->interrupts()->sole()->isResolved())->toBeTrue()
        ->and($run->interrupts()->sole()->resolution)->toBe(['cancelled' => true]);

    $fake->assertCancelled('cancel-parked');

    // Neither resumable nor retryable afterwards.
    expect(fn () => $run->resume(['ok' => true]))->toThrow(WorkflowException::class)
        ->and(fn () => $run->refresh()->cancel())->toThrow(WorkflowException::class);
});

it('cancels a failed run', function () {
    CounterBoomStep::$fail = true;

    defineWorkflow('cancel-failed', fn (WorkflowDefinition $workflow) => $workflow
        ->step(CounterBoomStep::class));

    try {
        AgentWorkflow::start('cancel-failed', []);
    } catch (RuntimeException) {
        // expected
    }

    $run = WorkflowRun::sole();

    expect($run->cancel()->status)->toBe(RunStatus::Cancelled)
        ->and(fn () => $run->refresh()->retry())->toThrow(WorkflowException::class);
});

it('refuses to cancel a completed run', function () {
    defineWorkflow('cancel-completed', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    $run = AgentWorkflow::start('cancel-completed', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(fn () => $run->cancel())->toThrow(WorkflowException::class);
});

it('discards an in-flight step result when the run was cancelled mid-step', function () {
    Event::fake([WorkflowCancelled::class]);

    $definition = defineWorkflow('cancel-midstep', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(CounterBoomStep::class));

    $run = WorkflowRun::create([
        'name' => 'cancel-midstep',
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

    // The operator cancels while the worker is mid-step...
    $run->cancel();

    // ...and the worker finishes and tries to commit its result.
    app(Progression::class)->complete(
        $run->refresh(), $definition, $definition->findStep('PrepareStep'), $stepRow, WorkflowState::make(['prepared' => true]),
    );

    $run->refresh();

    // The result was discarded at the boundary: no advance, no next step.
    expect($run->status)->toBe(RunStatus::Cancelled)
        ->and($run->state)->toBe([])
        ->and(CounterBoomStep::$count)->toBe(0)
        ->and($stepRow->refresh()->status)->toBe(StepStatus::Failed);
});
