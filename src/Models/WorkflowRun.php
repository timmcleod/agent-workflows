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
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Events\WorkflowCancelled;
use TimMcLeod\AgentWorkflows\Events\WorkflowResumed;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Jobs\WorkflowStepJob;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;
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
 * @property Carbon $created_at
 * @property Carbon $updated_at
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

    /**
     * The run's checkpointed state, hydrated as the workflow's declared
     * state class. Falls back to the base WorkflowState when the workflow
     * is not registered in this process (drifted or removed definitions).
     */
    public function workflowState(): WorkflowState
    {
        $registry = app(WorkflowRegistry::class);

        if ($registry->has($this->name)) {
            return $registry->get($this->name)->makeState($this->state ?? []);
        }

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
        // The whole transition runs on a locked re-read so two concurrent
        // resume() calls (double-clicked approve button, retried request)
        // yield exactly one resumption — the loser sees the new status.
        $interrupt = DB::transaction(function () use (&$response, $by) {
            $run = static::query()->lockForUpdate()->findOrFail($this->id);

            if ($run->status !== RunStatus::AwaitingHuman) {
                throw new WorkflowException(
                    "Only runs awaiting a human can be resumed; run [{$run->id}] is [{$run->status->value}]."
                );
            }

            $interrupt = $run->openInterrupt();

            if ($interrupt->response_schema !== null) {
                $response = Validator::make($response, $interrupt->response_schema)->validate();
            }

            $state = $run->workflowState();

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

            if ($by !== null) {
                $interrupt->resolvedBy()->associate($by);
            }

            $interrupt->fill([
                'resolution' => $response,
                'resolved_at' => now(),
            ])->save();

            $run->update([
                'state' => $state->all(),
                'status' => RunStatus::Pending,
            ]);

            return $interrupt;
        });

        event(new WorkflowResumed($this->refresh(), $interrupt));

        // Re-dispatch the interrupted step itself: await steps see the
        // resolved interrupt and advance; agent steps replay the decisions.
        WorkflowStepJob::dispatch($this->id, $interrupt->step_id)->afterCommit();

        return $this->refresh();
    }

    /**
     * Deliver a named application event to a run parked by awaitEvent().
     *
     * @param  array<string, mixed>  $payload  merged into state
     */
    public function deliverEvent(string $event, array $payload = []): static
    {
        $interrupt = DB::transaction(function () use ($event, $payload) {
            $run = static::query()->lockForUpdate()->findOrFail($this->id);

            if ($run->status !== RunStatus::AwaitingEvent) {
                throw new WorkflowException(
                    "Run [{$run->id}] is not awaiting an event; it is [{$run->status->value}]."
                );
            }

            $interrupt = $run->openInterrupt();
            $expected = $interrupt->context['event'] ?? null;

            if ($expected !== $event) {
                throw new WorkflowException(
                    "Run [{$run->id}] is waiting for event [{$expected}], not [{$event}]."
                );
            }

            $interrupt->fill([
                'resolution' => $payload,
                'resolved_at' => now(),
            ])->save();

            $run->update([
                'state' => $run->workflowState()->merge($payload)->all(),
                'status' => RunStatus::Pending,
            ]);

            return $interrupt;
        });

        event(new WorkflowResumed($this->refresh(), $interrupt));

        WorkflowStepJob::dispatch($this->id, $interrupt->step_id)->afterCommit();

        return $this->refresh();
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
     * Cancel the run. Takes effect immediately for pending, parked, or
     * failed runs; for a run mid-step, the in-flight step's result is
     * discarded at the step boundary (it will not advance a cancelled run).
     * Open interrupts are resolved as cancelled. Refused once terminal.
     */
    public function cancel(): static
    {
        DB::transaction(function () {
            $run = static::query()->lockForUpdate()->findOrFail($this->id);

            if ($run->status->isTerminal()) {
                throw new WorkflowException(
                    "Run [{$run->id}] is already [{$run->status->value}] and cannot be cancelled."
                );
            }

            $run->interrupts()->whereNull('resolved_at')->update([
                'resolution' => json_encode(['cancelled' => true]),
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);

            $run->update([
                'status' => RunStatus::Cancelled,
                'finished_at' => now(),
            ]);
        });

        event(new WorkflowCancelled($this->refresh()));

        return $this;
    }

    /**
     * Re-dispatch a failed run from its checkpoint. Only the failed step is
     * re-executed — every step before it keeps its committed result.
     */
    public function retry(): static
    {
        $step = DB::transaction(function () {
            $run = static::query()->lockForUpdate()->findOrFail($this->id);

            if ($run->status !== RunStatus::Failed) {
                throw new WorkflowException(
                    "Only failed runs can be retried; run [{$run->id}] is [{$run->status->value}]."
                );
            }

            $step = $run->failed_step ?? $run->current_step;

            // A hard-killed worker can leave in-flight audit rows that
            // would block the new claim — including branch rows of a
            // parallel fan-out, which would otherwise wedge every retry
            // as a "duplicate delivery". A failed run has no legitimate
            // in-flight work, so the retry supersedes all of it.
            $run->steps()
                ->where('status', StepStatus::Running->value)
                ->update([
                    'status' => StepStatus::Failed->value,
                    'error' => 'Superseded by retry.',
                    'finished_at' => now(),
                ]);

            $run->update([
                'status' => RunStatus::Pending,
                'failed_step' => null,
                'failure_reason' => null,
                'finished_at' => null,
            ]);

            return $step;
        });

        WorkflowStepJob::dispatch($this->id, $step)->afterCommit();

        return $this->refresh();
    }
}
