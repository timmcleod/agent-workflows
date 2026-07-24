<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_workflow_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->index();
            $table->string('version', 64);
            $table->string('status')->index();
            $table->string('current_step')->nullable();
            $table->json('state');
            $table->nullableMorphs('participant');
            $table->string('failed_step')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('run_id')->constrained('agent_workflow_runs')->cascadeOnDelete();
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

            $table->index(['run_id', 'step_id']);
        });

        Schema::create('agent_workflow_interrupts', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('run_id')->constrained('agent_workflow_runs')->cascadeOnDelete();
            $table->string('step_id');
            $table->text('reason')->nullable();
            $table->json('response_schema')->nullable();
            $table->nullableMorphs('resolved_by');
            $table->json('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_workflow_interrupts');
        Schema::dropIfExists('agent_workflow_steps');
        Schema::dropIfExists('agent_workflow_runs');
    }
};
