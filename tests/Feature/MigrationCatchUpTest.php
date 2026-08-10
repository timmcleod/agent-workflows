<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('adds timeout_at to interrupts tables created before v0.9', function () {
    $interrupts = config('agent-workflows.tables.interrupts', 'agent_workflow_interrupts');

    // Rewind the table to its v0.8 shape (index first — SQLite cannot
    // drop a column that an index still references).
    Schema::table($interrupts, function (Blueprint $table) use ($interrupts) {
        $table->dropIndex($interrupts.'_timeout_at_index');
    });

    Schema::table($interrupts, function (Blueprint $table) {
        $table->dropColumn('timeout_at');
    });

    expect(Schema::hasColumn($interrupts, 'timeout_at'))->toBeFalse();

    $migration = require __DIR__.'/../../database/migrations/0001_01_01_000001_add_timeout_at_to_agent_workflow_interrupts.php';

    $migration->up();

    expect(Schema::hasColumn($interrupts, 'timeout_at'))->toBeTrue();

    // Idempotent: a fresh install (whose base migration already created
    // the column) runs this as a no-op.
    $migration->up();

    expect(Schema::hasColumn($interrupts, 'timeout_at'))->toBeTrue();
});

it('adds the key, group, and meta columns to runs tables created before v0.13', function () {
    $runs = config('agent-workflows.tables.runs', 'agent_workflow_runs');

    // Rewind the table to its v0.12 shape (indexes first — SQLite cannot
    // drop a column that an index still references).
    Schema::table($runs, function (Blueprint $table) use ($runs) {
        $table->dropUnique($runs.'_name_active_key_unique');
        $table->dropIndex($runs.'_name_key_index');
        $table->dropIndex($runs.'_group_key_index');
    });

    Schema::table($runs, function (Blueprint $table) {
        $table->dropColumn(['key', 'active_key', 'group_key', 'settled_at', 'meta']);
    });

    expect(Schema::hasColumn($runs, 'key'))->toBeFalse();

    $migration = require __DIR__.'/../../database/migrations/0001_01_01_000004_add_keys_groups_and_meta_to_agent_workflow_runs.php';

    $migration->up();

    expect(Schema::hasColumn($runs, 'key'))->toBeTrue()
        ->and(Schema::hasColumn($runs, 'active_key'))->toBeTrue()
        ->and(Schema::hasColumn($runs, 'group_key'))->toBeTrue()
        ->and(Schema::hasColumn($runs, 'settled_at'))->toBeTrue()
        ->and(Schema::hasColumn($runs, 'meta'))->toBeTrue();

    // Idempotent: a fresh install (whose base migration already created
    // the columns) runs this as a no-op.
    $migration->up();

    expect(Schema::hasColumn($runs, 'key'))->toBeTrue();
});

it('drops the run_id foreign keys from installs created before v0.10', function () {
    // A v0.9-era schema, FK constraints included.
    config()->set('agent-workflows.tables', [
        'runs' => 'legacy_runs',
        'steps' => 'legacy_steps',
        'interrupts' => 'legacy_interrupts',
    ]);

    Schema::create('legacy_runs', function (Blueprint $table) {
        $table->ulid('id')->primary();
        $table->timestamps();
    });

    Schema::create('legacy_steps', function (Blueprint $table) {
        $table->id();
        $table->foreignUlid('run_id')->constrained('legacy_runs')->cascadeOnDelete();
    });

    Schema::create('legacy_interrupts', function (Blueprint $table) {
        $table->id();
        $table->foreignUlid('run_id')->constrained('legacy_runs')->cascadeOnDelete();
    });

    expect(Schema::getForeignKeys('legacy_steps'))->not->toBe([]);

    $migration = require __DIR__.'/../../database/migrations/0001_01_01_000003_drop_agent_workflows_foreign_keys.php';

    $migration->up();

    expect(Schema::getForeignKeys('legacy_steps'))->toBe([])
        ->and(Schema::getForeignKeys('legacy_interrupts'))->toBe([]);

    // Idempotent: fresh installs (no constraints) run this as a no-op.
    $migration->up();
});
