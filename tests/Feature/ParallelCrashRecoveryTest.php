<?php

use Illuminate\Support\Facades\DB;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Jobs\WorkflowStepJob;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\BranchAStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\BranchBStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

beforeEach(fn () => FlakyStep::$fail = false);

/**
 * Build a run that failed at its parallel step with one branch's audit
 * row stranded in "running" — exactly what a SIGKILL'd worker leaves
 * behind once the batch (or the sweep) has failed the run.
 */
function crashedParallelRun(string $name, WorkflowDefinition $definition, string $strandedBranch): WorkflowRun
{
    $run = WorkflowRun::create([
        'name' => $name,
        'version' => $definition->hash(),
        'status' => RunStatus::Failed,
        'current_step' => 'parallel:2',
        'failed_step' => 'parallel:2',
        'failure_reason' => 'Worker died mid-branch.',
        'state' => ['prepared' => true],
    ]);

    $run->steps()->create([
        'step_id' => 'parallel:2',
        'type' => StepType::Parallel,
        'status' => StepStatus::Failed,
        'attempt' => 1,
        'error' => 'Worker died mid-branch.',
        'started_at' => now()->subMinutes(30),
    ]);

    $run->steps()->create([
        'step_id' => $strandedBranch,
        'type' => StepType::Callback,
        'status' => StepStatus::Running,
        'attempt' => 1,
        'started_at' => now()->subMinutes(30),
    ]);

    return $run;
}

it('recovers a batch fan-out whose branch crashed mid-flight (retry clears stale rows)', function () {
    config()->set('queue.default', 'database');

    $definition = defineWorkflow('crashed-fanout', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([BranchAStep::class, BranchBStep::class])
        ->step(FinalizeStep::class));

    $run = crashedParallelRun('crashed-fanout', $definition, 'BranchBStep');

    $run = $run->retry();

    $guard = 0;
    while (DB::table('jobs')->count() > 0 && $guard++ < 25) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    $run->refresh();

    // The stale branch attempt was superseded, the retried fan-out ran
    // cleanly, and BOTH branches' work is present in the merged state.
    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['a'])->toBe('from-a')
        ->and($run->state['b'])->toBe('from-b')
        ->and($run->state['finalized'])->toBeTrue()
        ->and($run->steps()->where('step_id', 'BranchBStep')->where('status', StepStatus::Failed->value)->where('error', 'Superseded by retry.')->count())->toBe(1);
});

it('recovers a sync fan-out whose branch crashed, with no branch state silently lost', function () {
    $definition = defineWorkflow('crashed-sync-fanout', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([BranchAStep::class, BranchBStep::class], mode: 'sync')
        ->step(FinalizeStep::class));

    $run = crashedParallelRun('crashed-sync-fanout', $definition, 'BranchBStep');

    $run = $run->retry();

    // The retried fan-out must carry BOTH branches' contributions — the
    // crashed branch's work must never be silently dropped from the merge.
    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['a'])->toBe('from-a')
        ->and($run->state['b'])->toBe('from-b')
        ->and($run->state['finalized'])->toBeTrue();
});

it('fails loudly instead of fabricating a result when a branch attempt is already in flight', function () {
    defineWorkflow('inflight-fanout', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([BranchAStep::class, BranchBStep::class], mode: 'sync')
        ->step(FinalizeStep::class));

    // Strand an in-flight branch row WITHOUT failing the run, so the
    // duplicate-delivery guard (not retry's cleanup) is what engages.
    $definition = defineWorkflow('inflight-fanout-b', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([BranchAStep::class, BranchBStep::class], mode: 'sync')
        ->step(FinalizeStep::class));

    $run = WorkflowRun::create([
        'name' => 'inflight-fanout-b',
        'version' => $definition->hash(),
        'status' => RunStatus::Running,
        'current_step' => 'parallel:2',
        'state' => ['prepared' => true],
    ]);

    $run->steps()->create([
        'step_id' => 'BranchBStep',
        'type' => StepType::Callback,
        'status' => StepStatus::Running,
        'attempt' => 1,
        'started_at' => now(),
    ]);

    $job = new WorkflowStepJob($run->id, 'parallel:2');

    try {
        $job->handle(app(WorkflowRegistry::class));
        $this->fail('Expected the stale in-flight branch to fail the fan-out.');
    } catch (Throwable $e) {
        expect($e->getMessage())->toContain('already has an attempt in flight');
    }

    // The run did NOT complete with BranchB's work missing.
    expect($run->refresh()->status)->not->toBe(RunStatus::Completed);
});

it('merges only the current generation of branch results after a retry', function () {
    config()->set('queue.default', 'database');

    SummarizeAgent::fake(['first generation', 'second generation']);

    defineWorkflow('generational', fn (WorkflowDefinition $workflow) => $workflow
        ->step(SummarizeAgent::class, prompt: 'Summarize.')
        ->parallel([BranchAStep::class, FlakyStep::class])
        ->step(FinalizeStep::class));

    FlakyStep::$fail = true;

    $run = AgentWorkflow::start('generational', []);

    $guard = 0;
    while (DB::table('jobs')->count() > 0 && $guard++ < 25) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    // First generation: BranchA completed, FlakyStep failed the fan-out.
    expect($run->refresh()->status)->toBe(RunStatus::Failed);

    FlakyStep::$fail = false;

    $run = $run->retry();

    $guard = 0;
    while (DB::table('jobs')->count() > 0 && $guard++ < 25) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['a'])->toBe('from-a')
        // Both generations audited; the second one's rows fed the merge.
        ->and($run->steps()->where('step_id', 'BranchAStep')->count())->toBe(2);
});

it('does not execute branches of a cancelled run', function () {
    config()->set('queue.default', 'database');

    defineWorkflow('cancel-fanout', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([BranchAStep::class, BranchBStep::class])
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('cancel-fanout', []);

    // Work the queue just enough to fan out (start step + parallel step),
    // leaving the branch jobs themselves queued.
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

    $run->refresh()->cancel();

    $guard = 0;
    while (DB::table('jobs')->count() > 0 && $guard++ < 25) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    $run->refresh();

    // The queued branches refused to run: no branch work in state, no
    // completed branch rows, and the run stayed cancelled.
    expect($run->status)->toBe(RunStatus::Cancelled)
        ->and($run->state)->not->toHaveKey('a')
        ->and($run->state)->not->toHaveKey('b')
        ->and($run->steps()->whereIn('step_id', ['BranchAStep', 'BranchBStep'])->where('status', StepStatus::Completed->value)->count())->toBe(0);
});

it('refuses to execute branches against a drifted definition in strict mode', function () {
    config()->set('queue.default', 'database');

    defineWorkflow('drifting-fanout', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([BranchAStep::class, BranchBStep::class])
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('drifting-fanout', []);

    // Fan out (start + parallel step), leaving branch jobs queued...
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

    // ...then deploy a changed definition while they sit on the queue.
    defineWorkflow('drifting-fanout', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([BranchAStep::class, BranchBStep::class, FlakyStep::class])
        ->step(FinalizeStep::class));

    $guard = 0;
    while (DB::table('jobs')->count() > 0 && $guard++ < 25) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    $run->refresh();

    // The queued branches refused to execute the NEW definition.
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failure_reason)->toContain('definition has changed')
        ->and($run->steps()->whereIn('step_id', ['BranchAStep', 'BranchBStep'])->where('status', StepStatus::Completed->value)->count())->toBe(0);
});
