<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\TransformStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

beforeEach(function () {
    FlakyStep::$fail = false;

    defineWorkflow('five-steps', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(TransformStep::class)
        ->step(TransformStep::class)
        ->step(FlakyStep::class)
        ->step(FinalizeStep::class));
});

it('retries a failed run from the failed step, not from the beginning', function () {
    FlakyStep::$fail = true;

    try {
        AgentWorkflow::start('five-steps', ['value' => 1]);
        $this->fail('The flaky step should have thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Flaky step exploded.');
    }

    $run = WorkflowRun::sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failed_step)->toBe('FlakyStep')
        ->and($run->failure_reason)->toBe('Flaky step exploded.')
        // The checkpoint holds everything steps 1-3 produced.
        ->and($run->state['sequence'])->toBe(['prepare', 'transform', 'transform'])
        ->and($run->state['value'])->toBe(4);

    // Steps 1-3 completed, step 4 failed, step 5 never ran.
    expect($run->steps()->where('status', StepStatus::Completed->value)->pluck('step_id')->all())
        ->toBe(['PrepareStep', 'TransformStep', 'TransformStep:2'])
        ->and($run->steps()->where('status', StepStatus::Failed->value)->sole()->step_id)->toBe('FlakyStep')
        ->and($run->steps()->where('step_id', 'FinalizeStep')->count())->toBe(0);

    FlakyStep::$fail = false;

    $run = $run->retry();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->failed_step)->toBeNull()
        ->and($run->failure_reason)->toBeNull()
        // Steps 1-3 did NOT re-run: still exactly one attempt each.
        ->and($run->state['sequence'])->toBe(['prepare', 'transform', 'transform', 'flaky', 'finalize'])
        ->and($run->state['value'])->toBe(4)
        ->and($run->steps()->where('step_id', 'PrepareStep')->count())->toBe(1)
        ->and($run->steps()->where('step_id', 'TransformStep')->count())->toBe(1)
        // The flaky step has two attempts on record: the failure and the success.
        ->and($run->steps()->where('step_id', 'FlakyStep')->orderBy('id')->pluck('status')->all())
        ->toBe([StepStatus::Failed, StepStatus::Completed])
        ->and($run->steps()->where('step_id', 'FlakyStep')->orderBy('id')->pluck('attempt')->all())
        ->toBe([1, 2])
        ->and($run->steps()->where('step_id', 'FinalizeStep')->count())->toBe(1);
});

it('refuses to retry a run that has not failed', function () {
    $run = AgentWorkflow::start('five-steps', ['value' => 1]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(fn () => $run->retry())->toThrow(WorkflowException::class);
});
