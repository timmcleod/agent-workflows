<?php

namespace TimMcLeod\AgentWorkflows\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Enums\StepType;

class WorkflowStep extends Model
{
    protected $table = 'agent_workflow_steps';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => StepType::class,
            'status' => StepStatus::class,
            'output_state' => 'array',
            'usage' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkflowRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'run_id');
    }
}
