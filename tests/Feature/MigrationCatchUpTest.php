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
