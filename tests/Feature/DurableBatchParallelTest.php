<?php

use Illuminate\Support\Facades\DB;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\BranchAStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\BranchBStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;

it('runs a parallel step as a real Bus::batch on the database queue driver', function () {
    config()->set('queue.default', 'database');

    AgentWorkflow::define('queued-fanout')
        ->step(PrepareStep::class)
        ->parallel([BranchAStep::class, BranchBStep::class])
        ->step(FinalizeStep::class);

    $run = AgentWorkflow::start('queued-fanout', []);

    // Nothing ran yet — the first step is genuinely queued.
    expect($run->status)->toBe(RunStatus::Pending)
        ->and(DB::table('jobs')->count())->toBe(1);

    // Drain the queue the way a worker would, one job at a time.
    $guard = 0;
    while (DB::table('jobs')->count() > 0 && $guard++ < 25) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['a'])->toBe('from-a')
        ->and($run->state['b'])->toBe('from-b')
        ->and($run->state['finalized'])->toBeTrue()
        ->and(DB::table('job_batches')->count())->toBe(1)
        ->and($run->steps()->where('step_id', 'parallel:2')->sole()->status)->toBe(StepStatus::Completed);
});
