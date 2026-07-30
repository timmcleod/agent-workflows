<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the run_id foreign key constraints on installs created before
 * v0.10, converging them with fresh installs (whose base migration no
 * longer defines them). Referential integrity is enforced by the engine
 * — child rows are only ever created through a run — and cascade
 * deletes moved to the model layer (WorkflowRun::booted), so the
 * constraints bought nothing but write overhead and lock coupling.
 *
 * Run-scoped lookups stay indexed without the FK's implicit index:
 * steps by the (run_id, step_id, attempt) unique prefix, interrupts by
 * (run_id, resolved_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            config('agent-workflows.tables.steps', 'agent_workflow_steps'),
            config('agent-workflows.tables.interrupts', 'agent_workflow_interrupts'),
        ];

        foreach ($tables as $table) {
            if ($this->hasRunForeignKey($table)) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropForeign(['run_id']);
                });
            }
        }
    }

    public function down(): void
    {
        // Constraints are gone by design; nothing to restore.
    }

    protected function hasRunForeignKey(string $table): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        return collect(Schema::getForeignKeys($table))
            ->contains(fn (array $key) => $key['columns'] === ['run_id']);
    }
};
