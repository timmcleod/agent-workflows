<?php

namespace TimMcLeod\AgentWorkflows\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $run_id
 * @property string $step_id
 * @property string|null $reason
 * @property array<string, mixed>|null $response_schema
 * @property string|null $resolved_by_type
 * @property int|string|null $resolved_by_id
 * @property array<string, mixed>|null $resolution
 * @property Carbon|null $resolved_at
 */
class WorkflowInterrupt extends Model
{
    protected $table = 'agent_workflow_interrupts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'response_schema' => 'array',
            'resolution' => 'array',
            'resolved_at' => 'datetime',
        ];
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
