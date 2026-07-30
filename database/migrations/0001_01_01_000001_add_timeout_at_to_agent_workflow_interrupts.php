<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catch-up for installs migrated at v0.8 or earlier: v0.9 added the
 * timeout_at column to the base create-tables migration, which Laravel
 * records as already run — so upgraders never received the column and
 * broke on the first interrupt. No-op on fresh installs, whose base
 * migration already includes it.
 *
 * (From v0.10 on, any change to the base migration ships with a paired
 * additive migration, so existing installs always converge on the fresh
 * install schema via `php artisan migrate`.)
 */
return new class extends Migration
{
    public function up(): void
    {
        $interrupts = config('agent-workflows.tables.interrupts', 'agent_workflow_interrupts');

        if (! Schema::hasColumn($interrupts, 'timeout_at')) {
            Schema::table($interrupts, function (Blueprint $table) {
                $table->timestamp('timeout_at')->nullable()->index()->after('resolution');
            });
        }
    }

    public function down(): void
    {
        // The column belongs to the base migration on fresh installs;
        // dropping it here would leave the base schema broken, so only
        // the up() is reversible in spirit — down() is a deliberate no-op.
    }
};
