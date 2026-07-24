<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Exceptions\StateMergeConflictException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\BranchAStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\BranchBStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\ConflictAStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\ConflictBStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

it('fans out into a durable batch and merges the branch states', function () {
    defineWorkflow('fanout', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([BranchAStep::class, BranchBStep::class])
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('fanout', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['a'])->toBe('from-a')
        ->and($run->state['b'])->toBe('from-b')
        ->and($run->state['finalized'])->toBeTrue()
        // Audit: both branches and the parallel step itself completed.
        ->and($run->steps()->where('step_id', 'BranchAStep')->sole()->status)->toBe(StepStatus::Completed)
        ->and($run->steps()->where('step_id', 'BranchBStep')->sole()->status)->toBe(StepStatus::Completed)
        ->and($run->steps()->where('step_id', 'parallel:2')->sole()->status)->toBe(StepStatus::Completed);
});

it('fails the run when branches write conflicting values without a merge strategy', function () {
    defineWorkflow('clash', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([ConflictAStep::class, ConflictBStep::class]));

    try {
        AgentWorkflow::start('clash', []);
    } catch (StateMergeConflictException) {
        // surfaces on the sync queue
    }

    $run = WorkflowRun::sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failed_step)->toBe('parallel:2')
        ->and($run->failure_reason)->toContain('conflicting values')
        ->and($run->failure_reason)->toContain('shared');
});

it('resolves conflicts with a custom merge strategy', function () {
    defineWorkflow('resolved', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel(
            [ConflictAStep::class, ConflictBStep::class],
            merge: fn (array $branches, array $input) => array_merge($input, [
                'shared' => $branches['ConflictAStep']['shared'].'+'.$branches['ConflictBStep']['shared'],
            ]),
        ));

    $run = AgentWorkflow::start('resolved', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['shared'])->toBe('alpha+beta');
});

it('runs branches in-process in sync mode', function () {
    defineWorkflow('sync-fanout', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([BranchAStep::class, BranchBStep::class], mode: 'sync')
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('sync-fanout', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['a'])->toBe('from-a')
        ->and($run->state['b'])->toBe('from-b')
        ->and($run->state['finalized'])->toBeTrue();
});

it('fails the run at the parallel step when a branch fails, and retries the whole fan-out', function () {
    FlakyStep::$fail = true;

    defineWorkflow('half-boom', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([BranchAStep::class, FlakyStep::class])
        ->step(FinalizeStep::class));

    try {
        AgentWorkflow::start('half-boom', []);
    } catch (RuntimeException) {
        // the branch failure surfaces on the sync queue
    }

    $run = WorkflowRun::sole();

    expect($run->status)->toBe(RunStatus::Failed)
        // Retry granularity is the parallel step, not the individual branch.
        ->and($run->failed_step)->toBe('parallel:2')
        ->and($run->steps()->where('step_id', 'BranchAStep')->sole()->status)->toBe(StepStatus::Completed)
        ->and($run->steps()->where('step_id', 'FlakyStep')->sole()->status)->toBe(StepStatus::Failed);

    FlakyStep::$fail = false;

    $run = $run->retry();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['a'])->toBe('from-a')
        ->and($run->state['finalized'])->toBeTrue()
        // Both branches re-ran on retry — documented fan-out retry semantics.
        ->and($run->steps()->where('step_id', 'BranchAStep')->count())->toBe(2)
        ->and($run->steps()->where('step_id', 'FlakyStep')->count())->toBe(2);
});
