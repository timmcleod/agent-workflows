<?php

use Illuminate\Support\Facades\DB;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

function staleRunWithAttempt(string $name, $attemptStartedAt): WorkflowRun
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

    $run->steps()->create([
        'step_id' => 'PrepareStep',
        'type' => StepType::Callback,
        'status' => StepStatus::Running,
        'attempt' => 1,
        'started_at' => $attemptStartedAt,
    ]);

    // Backdate the run past the staleness threshold (raw update — the
    // model would refresh the timestamp).
    DB::table($run->getTable())->where('id', $run->id)->update(['updated_at' => now()->subHour()]);

    return $run->refresh();
}

it('does not supersede an attempt that is genuinely still executing', function () {
    // The run row is stale (long step, nothing committed for an hour),
    // but the current attempt started recently: a worker is on it.
    $run = staleRunWithAttempt('busy-long-step', now()->subMinute());

    $this->artisan('agent-workflows:sweep')->assertSuccessful();

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->steps()->sole()->status)->toBe(StepStatus::Running)
        ->and($run->steps()->sole()->error)->toBeNull();
});

it('supersedes an attempt whose worker died past the cutoff', function () {
    $run = staleRunWithAttempt('dead-worker', now()->subHours(2));

    $this->artisan('agent-workflows:sweep')->assertSuccessful();

    $run->refresh();

    // The stale attempt was cleared and the swept re-dispatch completed
    // the run on the sync driver.
    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->steps()->where('status', StepStatus::Failed->value)->sole()->error)->toBe('Superseded by sweep.');
});

it('re-dispatches a stranded run once per staleness window, not once per tick', function () {
    config()->set('queue.default', 'database');
    config()->set('agent-workflows.sweep.stale_after', 600);

    $definition = defineWorkflow('backlogged', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    $run = WorkflowRun::create([
        'name' => 'backlogged',
        'version' => $definition->hash(),
        'status' => RunStatus::Pending,
        'current_step' => 'PrepareStep',
        'state' => [],
    ]);

    DB::table($run->getTable())->where('id', $run->id)->update(['updated_at' => now()->subHour()]);

    // Tick 1: the run is stale, one job dispatched onto the backlog.
    $this->artisan('agent-workflows:sweep')->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(1);

    // Ticks 2 and 3 fire while the queue is still backlogged: the run's
    // freshened updated_at keeps it out of the stale scan — no duplicate
    // jobs pile onto a queue that is already behind.
    $this->artisan('agent-workflows:sweep')->assertSuccessful();
    $this->artisan('agent-workflows:sweep')->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(1);
});
