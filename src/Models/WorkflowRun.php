<?php

namespace TimMcLeod\AgentWorkflows\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Jobs\WorkflowStepJob;
use TimMcLeod\AgentWorkflows\WorkflowState;

/**
 * @property string $id
 * @property string $name
 * @property string $version
 * @property RunStatus $status
 * @property string|null $current_step
 * @property array<string, mixed>|null $state
 * @property string|null $participant_type
 * @property int|string|null $participant_id
 * @property string|null $failed_step
 * @property string|null $failure_reason
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class WorkflowRun extends Model
{
    use HasUlids;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('agent-workflows.tables.runs', 'agent_workflow_runs');
    }

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

    /**
     * Re-dispatch a failed run from its checkpoint. Only the failed step is
     * re-executed — every step before it keeps its committed result.
     */
    public function retry(): static
    {
        if ($this->status !== RunStatus::Failed) {
            throw new WorkflowException(
                "Only failed runs can be retried; run [{$this->id}] is [{$this->status->value}]."
            );
        }

        $step = $this->failed_step ?? $this->current_step;

        $this->update([
            'status' => RunStatus::Pending,
            'failed_step' => null,
            'failure_reason' => null,
            'finished_at' => null,
        ]);

        WorkflowStepJob::dispatch($this->id, $step)->afterCommit();

        return $this->refresh();
    }
}
