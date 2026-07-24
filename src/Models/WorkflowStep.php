<?php

namespace TimMcLeod\AgentWorkflows\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
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
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
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
