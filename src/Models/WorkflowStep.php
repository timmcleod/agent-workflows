<?php

namespace TimMcLeod\AgentWorkflows\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Enums\StepType;

/**
 * @property int $id
 * @property string $run_id
 * @property string $step_id
 * @property StepType $type
 * @property StepStatus $status
 * @property int $attempt
 * @property string|null $input_state_hash
 * @property array<string, mixed>|null $output_state
 * @property array<string, int>|null $usage
 * @property array<int, array<string, mixed>>|null $calls
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class WorkflowStep extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('agent-workflows.tables.steps', 'agent_workflow_steps');
    }

    protected function casts(): array
    {
        return [
            'type' => StepType::class,
            'status' => StepStatus::class,
            'output_state' => 'array',
            'usage' => 'array',
            'calls' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Whether the steps table carries the per-call audit column (the
     * additive migration may not have run yet on an upgraded install).
     * Deliberately unmemoized, matching WorkflowRun::schemaHasKeyColumns():
     * one schema lookup per step write is nothing next to an agent turn.
     */
    public static function schemaHasCallsColumn(): bool
    {
        return Schema::hasColumn(
            config('agent-workflows.tables.steps', 'agent_workflow_steps'),
            'calls',
        );
    }

    /**
     * The per-call audit attribute to spread into a step-row write: empty
     * (rather than null-valued) on an install whose additive `calls`
     * migration has not run yet, so the write still succeeds there.
     *
     * @param  array<int, array<string, mixed>>|null  $calls
     * @return array{calls?: array<int, array<string, mixed>>|null}
     */
    public static function callsAudit(?array $calls): array
    {
        if (! static::schemaHasCallsColumn()) {
            return [];
        }

        return ['calls' => $calls !== null && $calls !== [] ? $calls : null];
    }

    /**
     * @return BelongsTo<WorkflowRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'run_id');
    }
}
