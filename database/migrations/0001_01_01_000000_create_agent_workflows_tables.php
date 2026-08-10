<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $runs = config('agent-workflows.tables.runs', 'agent_workflow_runs');
        $steps = config('agent-workflows.tables.steps', 'agent_workflow_steps');
        $interrupts = config('agent-workflows.tables.interrupts', 'agent_workflow_interrupts');

        Schema::create($runs, function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->index();
            // Singleton keys: `key` is the permanent record; `active_key`
            // mirrors it while the run is active and is nulled on terminal
            // transitions, so the unique index admits one ACTIVE run per
            // (name, key) while history accumulates freely. Relies on NULLs
            // not colliding in unique indexes (true everywhere but SQL
            // Server, which is not supported).
            $table->string('key')->nullable();
            $table->string('active_key')->nullable();
            // Run groups: `group_key` joins sibling runs; `settled_at`
            // (below) marks a terminal run as delivered by a
            // WorkflowGroupSettled event.
            $table->string('group_key')->nullable();
            $table->string('version', 64);
            $table->string('status')->index();
            $table->string('current_step')->nullable();
            $table->json('state');
            // App-owned metadata: the engine never reads or writes it.
            $table->json('meta')->nullable();
            $table->nullableMorphs('participant');
            $table->string('failed_step')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['name', 'key']);
            $table->unique(['name', 'active_key']);
            $table->index('group_key');
        });

        Schema::create($steps, function (Blueprint $table) {
            $table->id();
            // No FK constraint by design: referential integrity is enforced
            // by the engine (rows are only ever created through a run), and
            // cascade deletes happen at the model layer (WorkflowRun::booted).
            $table->ulid('run_id');
            $table->string('step_id');
            $table->string('type');
            $table->string('status');
            $table->unsignedInteger('attempt')->default(1);
            $table->string('input_state_hash', 64)->nullable();
            $table->json('output_state')->nullable();
            $table->json('usage')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The idempotency barrier: two workers claiming the same attempt
            // of the same step cannot both insert an audit row.
            $table->unique(['run_id', 'step_id', 'attempt']);
        });

        Schema::create($interrupts, function (Blueprint $table) {
            $table->id();
            $table->ulid('run_id');
            $table->string('step_id');
            $table->string('type')->default('human');
            $table->text('reason')->nullable();
            $table->json('response_schema')->nullable();
            $table->json('context')->nullable();
            $table->nullableMorphs('resolved_by');
            $table->json('resolution')->nullable();
            $table->timestamp('timeout_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists(config('agent-workflows.tables.interrupts', 'agent_workflow_interrupts'));
        Schema::dropIfExists(config('agent-workflows.tables.steps', 'agent_workflow_steps'));
        Schema::dropIfExists(config('agent-workflows.tables.runs', 'agent_workflow_runs'));
    }
};
