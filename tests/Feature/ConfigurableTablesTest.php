<?php

use Illuminate\Support\Facades\Schema;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowInterrupt;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Models\WorkflowStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;

it('reads table names from the config', function () {
    config()->set('agent-workflows.tables', [
        'runs' => 'wf_runs',
        'steps' => 'wf_steps',
        'interrupts' => 'wf_interrupts',
    ]);

    expect((new WorkflowRun)->getTable())->toBe('wf_runs')
        ->and((new WorkflowStep)->getTable())->toBe('wf_steps')
        ->and((new WorkflowInterrupt)->getTable())->toBe('wf_interrupts');
});

it('migrates and runs workflows against custom table names', function () {
    config()->set('agent-workflows.tables', [
        'runs' => 'wf_runs',
        'steps' => 'wf_steps',
        'interrupts' => 'wf_interrupts',
    ]);

    $migration = include __DIR__.'/../../database/migrations/0001_01_01_000000_create_agent_workflows_tables.php';
    $migration->up();

    expect(Schema::hasTable('wf_runs'))->toBeTrue()
        ->and(Schema::hasTable('wf_steps'))->toBeTrue()
        ->and(Schema::hasTable('wf_interrupts'))->toBeTrue();

    AgentWorkflow::define('custom-tables')->start(PrepareStep::class);

    $run = AgentWorkflow::start('custom-tables', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->getTable())->toBe('wf_runs')
        ->and($run->steps()->count())->toBe(1);
});
