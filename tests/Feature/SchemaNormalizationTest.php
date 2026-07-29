<?php

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

// Response schemas persist to the interrupt row's JSON column and are
// re-read at resume time. json_encode turns rule OBJECTS into {} — an
// empty constraint that accepts anything — so Stringable rules must be
// reduced to strings at definition time and the rest rejected outright.

it('enforces Stringable rule objects after JSON persistence', function () {
    defineWorkflow('rule-gate', fn (WorkflowDefinition $workflow) => $workflow
        ->awaitHuman(reason: 'Decide', schema: [
            'status' => ['required', Rule::in(['approved', 'rejected'])],
        ])
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('rule-gate', []);

    // The persisted schema kept the constraint in string form.
    expect($run->interrupts()->sole()->response_schema)
        ->toBe(['status' => ['required', 'in:"approved","rejected"']]);

    // A payload the original Rule::in would refuse is still refused.
    expect(fn () => $run->resume(['status' => 'totally-invalid']))
        ->toThrow(ValidationException::class);

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingHuman);

    $run = $run->resume(['status' => 'approved']);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['status'])->toBe('approved');
});

it('rejects closure rules at definition time', function () {
    expect(fn () => (new WorkflowDefinition('bad'))->awaitHuman(schema: [
        'value' => [fn () => true],
    ]))->toThrow(InvalidArgumentException::class, 'cannot survive JSON persistence');
});

it('rejects non-Stringable rule objects at definition time', function () {
    expect(fn () => (new WorkflowDefinition('bad'))->awaitHuman(schema: [
        'password' => ['required', Password::min(8)],
    ]))->toThrow(InvalidArgumentException::class, 'cannot survive JSON persistence');
});

it('hashes normalized rule objects by their constraint, not as empty objects', function () {
    $approved = (new WorkflowDefinition('h'))->awaitHuman(schema: [
        'status' => [Rule::in(['approved'])],
    ]);

    $rejected = (new WorkflowDefinition('h'))->awaitHuman(schema: [
        'status' => [Rule::in(['rejected'])],
    ]);

    expect($approved->hash())->not->toBe($rejected->hash());
});
