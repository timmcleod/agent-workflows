<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $runs = config('agent-workflows.tables.runs', 'agent_workflow_runs');

        // Idempotent: installs whose runs table already carries the columns
        // (or that point the config at another table mid-process) no-op.
        if (! Schema::hasTable($runs) || Schema::hasColumn($runs, 'key')) {
            return;
        }

        Schema::table($runs, function (Blueprint $table) {
            // Singleton keys: `key` is the permanent record for history
            // queries; `active_key` mirrors it while the run is active and
            // is nulled on terminal transitions, so the unique index below
            // admits one ACTIVE run per (name, key) while completed history
            // accumulates freely. Keyless runs are unaffected because NULLs
            // do not collide in unique indexes on SQLite, MySQL, MariaDB,
            // and Postgres. SQL Server treats NULLs as equal here and is
            // not supported.
            $table->string('key')->nullable()->after('name');
            $table->string('active_key')->nullable()->after('key');

            // Run groups: `group_key` joins sibling runs; `settled_at`
            // marks a terminal run as delivered by a WorkflowGroupSettled
            // event, giving consumers exactly-once delivery per run.
            $table->string('group_key')->nullable()->after('active_key');
            $table->timestamp('settled_at')->nullable()->after('finished_at');

            // App-owned metadata: the engine never reads or writes it.
            $table->json('meta')->nullable()->after('state');

            $table->index(['name', 'key']);
            $table->unique(['name', 'active_key']);
            $table->index('group_key');
        });
    }

    public function down(): void
    {
        $runs = config('agent-workflows.tables.runs', 'agent_workflow_runs');

        if (! Schema::hasTable($runs) || ! Schema::hasColumn($runs, 'key')) {
            return;
        }

        Schema::table($runs, function (Blueprint $table) {
            $table->dropUnique(['name', 'active_key']);
            $table->dropIndex(['name', 'key']);
            $table->dropIndex(['group_key']);
            $table->dropColumn(['key', 'active_key', 'group_key', 'settled_at', 'meta']);
        });
    }
};
