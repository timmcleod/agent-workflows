<?php

namespace TimMcLeod\AgentWorkflows\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use TimMcLeod\AgentWorkflows\Enums\InterruptType;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Events\WorkflowResumed;
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
     * Resume a run parked by awaitHuman() or an agent tool-approval pause.
     *
     * For awaitHuman interrupts the response is validated against the
     * interrupt's schema (Laravel validation rules) and merged into state.
     * For approval interrupts the response is the SDK's decisions map
     * (tool-call id => true/false or Decision objects) and is replayed into
     * the paused agent when the step re-runs.
     *
     * @param  array<string, mixed>  $response
     */
    public function resume(array $response = [], ?object $by = null): static
    {
        if ($this->status !== RunStatus::AwaitingHuman) {
            throw new WorkflowException(
                "Only runs awaiting a human can be resumed; run [{$this->id}] is [{$this->status->value}]."
            );
        }

        $interrupt = $this->openInterrupt();

        if ($interrupt->response_schema !== null) {
            $response = Validator::make($response, $interrupt->response_schema)->validate();
        }

        $state = $this->workflowState();

        if ($interrupt->type === InterruptType::Approval) {
            if ($response === []) {
                throw new WorkflowException(
                    'Resuming an approval interrupt requires a decisions map (tool-call id => decision).'
                );
            }

            $state->set("steps.{$interrupt->step_id}.approval_decisions", $response);
        } else {
            $state->merge($response);
        }

        return $this->resolveInterrupt($interrupt, $state, $response, $by);
    }

    /**
     * Deliver a named application event to a run parked by awaitEvent().
     *
     * @param  array<string, mixed>  $payload  merged into state
     */
    public function deliverEvent(string $event, array $payload = []): static
    {
        if ($this->status !== RunStatus::AwaitingEvent) {
            throw new WorkflowException(
                "Run [{$this->id}] is not awaiting an event; it is [{$this->status->value}]."
            );
        }

        $interrupt = $this->openInterrupt();
        $expected = $interrupt->context['event'] ?? null;

        if ($expected !== $event) {
            throw new WorkflowException(
                "Run [{$this->id}] is waiting for event [{$expected}], not [{$event}]."
            );
        }

        return $this->resolveInterrupt($interrupt, $this->workflowState()->merge($payload), $payload, null);
    }

    protected function openInterrupt(): WorkflowInterrupt
    {
        $interrupt = $this->interrupts()->whereNull('resolved_at')->latest('id')->first();

        if ($interrupt === null) {
            throw new WorkflowException("Run [{$this->id}] has no open interrupt.");
        }

        return $interrupt;
    }

    /**
     * @param  array<string, mixed>  $resolution
     */
    protected function resolveInterrupt(WorkflowInterrupt $interrupt, WorkflowState $state, array $resolution, ?object $by): static
    {
        DB::transaction(function () use ($interrupt, $state, $resolution, $by) {
            if ($by !== null) {
                $interrupt->resolvedBy()->associate($by);
            }

            $interrupt->fill([
                'resolution' => $resolution,
                'resolved_at' => now(),
            ])->save();

            $this->update([
                'state' => $state->all(),
                'status' => RunStatus::Pending,
            ]);
        });

        event(new WorkflowResumed($this, $interrupt));

        // Re-dispatch the interrupted step itself: await steps see the
        // resolved interrupt and advance; agent steps replay the decisions.
        WorkflowStepJob::dispatch($this->id, $interrupt->step_id)->afterCommit();

        return $this->refresh();
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
