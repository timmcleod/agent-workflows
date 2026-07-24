<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use TimMcLeod\AgentWorkflows\Enums\InterruptType;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\TestUser;

beforeEach(function () {
    AgentWorkflow::define('signoff')
        ->start(PrepareStep::class)
        ->awaitHuman(reason: 'Final sign-off required', schema: [
            'approved' => 'required|boolean',
            'notes' => 'nullable|string',
        ])
        ->then(FinalizeStep::class);
});

it('parks the run as awaiting_human with the reason and schema persisted', function () {
    $run = AgentWorkflow::start('signoff', []);

    expect($run->status)->toBe(RunStatus::AwaitingHuman)
        ->and($run->current_step)->toBe('await-human:2')
        ->and($run->finished_at)->toBeNull()
        ->and($run->steps()->where('step_id', 'FinalizeStep')->count())->toBe(0);

    $interrupt = $run->interrupts()->sole();

    expect($interrupt->type)->toBe(InterruptType::Human)
        ->and($interrupt->reason)->toBe('Final sign-off required')
        ->and($interrupt->response_schema)->toBe(['approved' => 'required|boolean', 'notes' => 'nullable|string'])
        ->and($interrupt->isResolved())->toBeFalse();

    expect($run->steps()->where('step_id', 'await-human:2')->sole()->status)->toBe(StepStatus::Interrupted);
});

it('validates the resume payload against the schema', function () {
    $run = AgentWorkflow::start('signoff', []);

    expect(fn () => $run->resume(['approved' => 'maybe']))->toThrow(ValidationException::class);

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingHuman)
        ->and($run->interrupts()->sole()->isResolved())->toBeFalse();
});

it('resumes with a validated payload, merging it into state', function () {
    Schema::create('test_users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $user = TestUser::create(['name' => 'Tim']);

    $run = AgentWorkflow::start('signoff', []);

    $run = $run->resume(['approved' => true, 'notes' => 'LGTM'], by: $user);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['approved'])->toBeTrue()
        ->and($run->state['notes'])->toBe('LGTM')
        ->and($run->state['finalized'])->toBeTrue();

    $interrupt = $run->interrupts()->sole();

    expect($interrupt->isResolved())->toBeTrue()
        ->and($interrupt->resolution)->toBe(['approved' => true, 'notes' => 'LGTM'])
        ->and($interrupt->resolved_by_type)->toBe(TestUser::class)
        ->and((int) $interrupt->resolved_by_id)->toBe($user->id);

    // The await step's audit trail: one interrupted attempt, one completed.
    expect($run->steps()->where('step_id', 'await-human:2')->orderBy('id')->pluck('status')->all())
        ->toBe([StepStatus::Interrupted, StepStatus::Completed]);
});

it('strips payload keys the schema does not cover', function () {
    $run = AgentWorkflow::start('signoff', []);

    $run = $run->resume(['approved' => true, 'sneaky' => 'value']);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state)->not->toHaveKey('sneaky');
});

it('refuses to resume a run that is not awaiting a human', function () {
    AgentWorkflow::define('plain')->start(PrepareStep::class);

    $run = AgentWorkflow::start('plain', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(fn () => $run->resume(['approved' => true]))->toThrow(WorkflowException::class);
});
