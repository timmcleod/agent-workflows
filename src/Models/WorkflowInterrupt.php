<?php

namespace TimMcLeod\AgentWorkflows\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use TimMcLeod\AgentWorkflows\Enums\InterruptType;

/**
 * @property int $id
 * @property string $run_id
 * @property string $step_id
 * @property InterruptType $type
 * @property string|null $reason
 * @property array<string, mixed>|null $response_schema
 * @property array<string, mixed>|null $context
 * @property string|null $resolved_by_type
 * @property int|string|null $resolved_by_id
 * @property array<string, mixed>|null $resolution
 * @property Carbon|null $timeout_at
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WorkflowInterrupt extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('agent-workflows.tables.interrupts', 'agent_workflow_interrupts');
    }

    protected function casts(): array
    {
        return [
            'type' => InterruptType::class,
            'response_schema' => 'array',
            'context' => 'array',
            'resolution' => 'array',
            'timeout_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * @return BelongsTo<WorkflowRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'run_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function resolvedBy(): MorphTo
    {
        return $this->morphTo('resolved_by');
    }
}
