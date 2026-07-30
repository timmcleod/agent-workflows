<?php

use Illuminate\Support\Facades\Schema;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowInterrupt;
use TimMcLeod\AgentWorkflows\Models\WorkflowStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

// The schema deliberately carries no foreign key constraints; the
// cascade that used to live on the FK is a model-level concern now.

it('deletes a run\'s steps and interrupts with it', function () {
    defineWorkflow('deletable', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off')
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('deletable', []);

    expect($run->status)->toBe(RunStatus::AwaitingHuman)
        ->and($run->steps()->count())->toBeGreaterThan(0)
        ->and($run->interrupts()->count())->toBe(1);

    $run->delete();

    // No orphans left behind.
    expect(WorkflowStep::query()->where('run_id', $run->id)->count())->toBe(0)
        ->and(WorkflowInterrupt::query()->where('run_id', $run->id)->count())->toBe(0);
});

it('defines no foreign key constraints on the package tables', function () {
    $steps = config('agent-workflows.tables.steps', 'agent_workflow_steps');
    $interrupts = config('agent-workflows.tables.interrupts', 'agent_workflow_interrupts');

    expect(Schema::getForeignKeys($steps))->toBe([])
        ->and(Schema::getForeignKeys($interrupts))->toBe([]);
});
