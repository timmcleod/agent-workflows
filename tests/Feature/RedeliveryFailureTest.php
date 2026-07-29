<?php

use Illuminate\Queue\MaxAttemptsExceededException;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Jobs\WorkflowStepJob;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

function runningRunWithAttempt(string $name, $startedAt): WorkflowRun
{
    $definition = defineWorkflow($name, fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

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
        'started_at' => $startedAt,
    ]);

    return $run;
}

it('does not fail a run whose step is genuinely still executing when the queue redelivers', function () {
    // A worker is 2 minutes into a long agent turn; retry_after expired
    // and the queue redelivered — the redelivery dies with
    // MaxAttemptsExceededException before handle() ever runs.
    $run = runningRunWithAttempt('redelivered', now()->subMinutes(2));

    (new WorkflowStepJob($run->id, 'PrepareStep'))->failed(new MaxAttemptsExceededException);

    // The healthy in-flight run was left alone.
    expect($run->refresh()->status)->toBe(RunStatus::Running)
        ->and($run->failed_step)->toBeNull();
});

it('still fails the run when the in-flight attempt is stale past the sweep cutoff', function () {
    config()->set('agent-workflows.sweep.stale_after', 600);

    $run = runningRunWithAttempt('stale-redelivered', now()->subHour());

    (new WorkflowStepJob($run->id, 'PrepareStep'))->failed(new MaxAttemptsExceededException);

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->failed_step)->toBe('PrepareStep');
});

it('still fails the run on genuine exceptions even with a fresh attempt on the books', function () {
    $run = runningRunWithAttempt('genuine-failure', now());

    (new WorkflowStepJob($run->id, 'PrepareStep'))->failed(new RuntimeException('The step exploded.'));

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->failure_reason)->toBe('The step exploded.');
});
