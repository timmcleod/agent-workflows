<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries the engine actually issues:
 *
 * - interrupts (run_id, resolved_at): every open-interrupt lookup on the
 *   resume/await paths filters exactly this pair. MySQL creates a run_id
 *   index implicitly for the foreign key, but PostgreSQL and SQLite do
 *   not — there, every lookup (and every cascade delete) scanned the
 *   whole interrupts table.
 * - runs (status, updated_at): the sweeper's stranded-run scan. With
 *   only the single-column status index it read every pending/running
 *   row and filtered updated_at in memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        $runs = config('agent-workflows.tables.runs', 'agent_workflow_runs');
        $interrupts = config('agent-workflows.tables.interrupts', 'agent_workflow_interrupts');

        Schema::table($interrupts, function (Blueprint $table) {
            $table->index(['run_id', 'resolved_at']);
        });

        Schema::table($runs, function (Blueprint $table) {
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        $runs = config('agent-workflows.tables.runs', 'agent_workflow_runs');
        $interrupts = config('agent-workflows.tables.interrupts', 'agent_workflow_interrupts');

        if (Schema::hasTable($interrupts) && Schema::hasIndex($interrupts, ['run_id', 'resolved_at'])) {
            Schema::table($interrupts, function (Blueprint $table) {
                $table->dropIndex(['run_id', 'resolved_at']);
            });
        }

        if (Schema::hasTable($runs) && Schema::hasIndex($runs, ['status', 'updated_at'])) {
            Schema::table($runs, function (Blueprint $table) {
                $table->dropIndex(['status', 'updated_at']);
            });
        }
    }
};
