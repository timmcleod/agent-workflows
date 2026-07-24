<?php

use TimMcLeod\AgentWorkflows\Enums\InterruptType;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

beforeEach(function () {
    defineWorkflow('payment-flow', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitEvent('payment.confirmed')
        ->step(FinalizeStep::class));
});

it('parks the run as awaiting_event with the event name persisted', function () {
    $run = AgentWorkflow::start('payment-flow', []);

    expect($run->status)->toBe(RunStatus::AwaitingEvent)
        ->and($run->current_step)->toBe('await-event:payment.confirmed');

    $interrupt = $run->interrupts()->sole();

    expect($interrupt->type)->toBe(InterruptType::Event)
        ->and($interrupt->context)->toBe(['event' => 'payment.confirmed']);
});

it('rejects delivery of the wrong event', function () {
    $run = AgentWorkflow::start('payment-flow', []);

    expect(fn () => $run->deliverEvent('refund.issued'))->toThrow(WorkflowException::class);

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingEvent);
});

it('resumes when the awaited event is delivered, merging the payload', function () {
    $run = AgentWorkflow::start('payment-flow', []);

    $run = $run->deliverEvent('payment.confirmed', ['amount' => 100]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['amount'])->toBe(100)
        ->and($run->state['finalized'])->toBeTrue()
        ->and($run->interrupts()->sole()->isResolved())->toBeTrue();
});

it('refuses resume() on a run awaiting an event', function () {
    $run = AgentWorkflow::start('payment-flow', []);

    expect(fn () => $run->resume(['approved' => true]))->toThrow(WorkflowException::class);
});
