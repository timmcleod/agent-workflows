<?php

use Illuminate\Support\Facades\Schema;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

it('boots the service provider and runs the migrations', function () {
    expect(app(WorkflowRegistry::class))->toBeInstanceOf(WorkflowRegistry::class);

    expect(Schema::hasTable('agent_workflow_runs'))->toBeTrue()
        ->and(Schema::hasTable('agent_workflow_steps'))->toBeTrue()
        ->and(Schema::hasTable('agent_workflow_interrupts'))->toBeTrue();
});
