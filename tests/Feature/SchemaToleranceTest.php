<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FlakyStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

// The deploy window: v0.13 code running against a runs table that has not
// been migrated yet. Existing behavior — start, complete, fail, retry,
// cancel, sweep — must keep working; only the new start() arguments
// (key/group) require the migration.

beforeEach(function () {
    FlakyStep::$fail = false;

    $runs = config('agent-workflows.tables.runs', 'agent_workflow_runs');

    // Rewind the runs table to its v0.12 shape (indexes first — SQLite
    // cannot drop a column that an index still references).
    Schema::table($runs, function (Blueprint $table) use ($runs) {
        $table->dropUnique($runs.'_name_active_key_unique');
        $table->dropIndex($runs.'_name_key_index');
        $table->dropIndex($runs.'_group_key_index');
    });

    Schema::table($runs, function (Blueprint $table) {
        $table->dropColumn(['key', 'active_key', 'group_key', 'settled_at', 'meta']);
    });
});

it('completes, fails, retries, and cancels runs against a pre-v0.13 schema', function () {
    defineWorkflow('legacy-complete', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    expect(AgentWorkflow::start('legacy-complete', [])->status)->toBe(RunStatus::Completed);

    // Failure and retry.
    FlakyStep::$fail = true;

    defineWorkflow('legacy-flaky', fn (WorkflowDefinition $workflow) => $workflow
        ->step(FlakyStep::class));

    try {
        AgentWorkflow::start('legacy-flaky', []);
        $this->fail('The flaky step should have thrown.');
    } catch (RuntimeException) {
        // expected
    }

    $failed = WorkflowRun::query()->where('name', 'legacy-flaky')->sole();

    expect($failed->status)->toBe(RunStatus::Failed);

    FlakyStep::$fail = false;

    expect($failed->retry()->status)->toBe(RunStatus::Completed);

    // Cancellation of a parked run.
    defineWorkflow('legacy-gate', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign-off'));

    $parked = AgentWorkflow::start('legacy-gate', []);

    expect($parked->cancel()->refresh()->status)->toBe(RunStatus::Cancelled);

    // The sweeper runs without touching the missing columns.
    $this->artisan('agent-workflows:sweep')->assertSuccessful();
});
