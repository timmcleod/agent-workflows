<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $steps = config('agent-workflows.tables.steps', 'agent_workflow_steps');

        // Idempotent: installs whose steps table already carries the column
        // (or that point the config at another table mid-process) no-op.
        if (! Schema::hasTable($steps) || Schema::hasColumn($steps, 'calls')) {
            return;
        }

        Schema::table($steps, function (Blueprint $table) {
            // Per-call audit detail: one entry per provider call made inside
            // the step attempt, in call order, each tagged with the SDK's
            // invocation id plus the responding provider/model, per-call
            // usage, finish reason, and (under the "full" audit mode) tool
            // calls and results. Null on rows written before this migration
            // and on steps that make no provider calls.
            $table->json('calls')->nullable()->after('usage');
        });
    }

    public function down(): void
    {
        $steps = config('agent-workflows.tables.steps', 'agent_workflow_steps');

        if (! Schema::hasTable($steps) || ! Schema::hasColumn($steps, 'calls')) {
            return;
        }

        Schema::table($steps, function (Blueprint $table) {
            $table->dropColumn('calls');
        });
    }
};
