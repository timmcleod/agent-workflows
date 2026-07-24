<?php

namespace TimMcLeod\AgentWorkflows\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\WorkflowState;

class WorkflowRun extends Model
{
    use HasUlids;

    protected $table = 'agent_workflow_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'state' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WorkflowStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class, 'run_id');
    }

    /**
     * @return HasMany<WorkflowInterrupt, $this>
     */
    public function interrupts(): HasMany
    {
        return $this->hasMany(WorkflowInterrupt::class, 'run_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function participant(): MorphTo
    {
        return $this->morphTo();
    }

    public function workflowState(): WorkflowState
    {
        return WorkflowState::make($this->state ?? []);
    }
}
