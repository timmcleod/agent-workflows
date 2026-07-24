<?php

namespace TimMcLeod\AgentWorkflows\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
