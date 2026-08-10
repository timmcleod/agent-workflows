<?php

namespace TimMcLeod\AgentWorkflows\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use TimMcLeod\AgentWorkflows\Enums\InterruptType;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Events\WorkflowCancelled;
use TimMcLeod\AgentWorkflows\Events\WorkflowResumed;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Jobs\WorkflowStepJob;
use TimMcLeod\AgentWorkflows\Runtime\DriftGuard;
use TimMcLeod\AgentWorkflows\Runtime\GroupSettler;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;
use TimMcLeod\AgentWorkflows\WorkflowState;

/**
 * @property string $id
 * @property string $name
 * @property string|null $key
 * @property string|null $active_key
 * @property string|null $group_key
 * @property string $version
 * @property RunStatus $status
 * @property string|null $current_step
 * @property array<string, mixed>|null $state
 * @property array<string, mixed>|null $meta
 * @property string|null $participant_type
 * @property int|string|null $participant_id
 * @property string|null $failed_step
 * @property string|null $failure_reason
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $settled_at
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

    protected static function booted(): void
    {
        // The schema carries no FK constraints (by design), so the cascade
        // lives here: deleting a run takes its audit trail and interrupts
        // with it. Note that mass deletes via the query builder bypass
        // model events — delete runs through their models.
        static::deleting(function (self $run) {
            $run->steps()->delete();
            $run->interrupts()->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'state' => 'array',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'settled_at' => 'datetime',
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
     * Whether the runs table carries the v0.13 key/group/meta columns.
     * Engine writes consult this so an upgraded package keeps existing
     * behavior working against a not-yet-migrated schema; the new start()
     * arguments themselves require the migration. Deliberately unmemoized —
     * terminal transitions are rare, and one schema lookup per transition
     * is nothing next to a workflow step.
     */
    public static function schemaHasKeyColumns(): bool
    {
        return Schema::hasColumn(
            config('agent-workflows.tables.runs', 'agent_workflow_runs'),
            'active_key',
        );
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
     * Merge values into the app-owned meta column — external references,
     * audit tags, notification receipts. The engine never reads or writes
     * meta: checkpoints, retries, sweeps, and resumes leave it untouched.
     * The merge re-reads under a lock so two writers do not clobber each
     * other; nested arrays are replaced, not deep-merged.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeMeta(array $values): static
    {
        DB::transaction(function () use ($values) {
            $fresh = static::query()->lockForUpdate()->findOrFail($this->id);

            $fresh->update(['meta' => array_merge($fresh->meta ?? [], $values)]);
        });

        return $this->refresh();
    }

    /**
     * Where the run is within its workflow, for progress displays: the
     * owning TOP-LEVEL step's id and human-facing label, its 1-based index,
     * and the definition's top-level step count. A cursor inside a parallel
     * branch or a condition branch reports the owning step, and loops do
     * not inflate the total.
     *
     * Never throws: when the definition is not registered in this process,
     * or has drifted past the cursor's step, the raw step id is returned as
     * the label with index and total zeroed.
     *
     * @return array{step: string|null, label: string, index: int, total: int, status: string}
     */
    public function progress(): array
    {
        $registry = app(WorkflowRegistry::class);

        if ($this->current_step !== null && $registry->has($this->name)) {
            $steps = $registry->get($this->name)->steps();

            foreach ($steps as $index => $step) {
                $ids = [$step->id, ...array_map(fn ($child) => $child->id, $step->children())];

                if (in_array($this->current_step, $ids, true)) {
                    return [
                        'step' => $step->id,
                        'label' => $step->displayLabel(),
                        'index' => $index + 1,
                        'total' => count($steps),
                        'status' => $this->status->value,
                    ];
                }
            }
        }

        return [
            'step' => $this->current_step,
            'label' => (string) $this->current_step,
            'index' => 0,
            'total' => 0,
            'status' => $this->status->value,
        ];
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

            $this->guardResumeDrift($run, $interrupt);

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
                $this->guardReservedKeys($response);

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

            $this->guardResumeDrift($run, $interrupt);

            if ($interrupt->response_schema !== null) {
                $payload = Validator::make($payload, $interrupt->response_schema)->validate();
            }

            $this->guardReservedKeys($payload);

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

    /**
     * Enforce the drift policy BEFORE the response is consumed. Without
     * this, a strict-mode resume would resolve the interrupt and merge the
     * human's answer, then fail the run when the dispatched step job hits
     * the drift guard — the response spent, the gate gone. Throwing here
     * rolls the whole transition back: the interrupt stays open.
     */
    protected function guardResumeDrift(WorkflowRun $run, WorkflowInterrupt $interrupt): void
    {
        $registry = app(WorkflowRegistry::class);

        // Unregistered on this process: the job-level guard adjudicates.
        if (! $registry->has($run->name)) {
            return;
        }

        app(DriftGuard::class)->check($run, $registry->get($run->name), $interrupt->step_id);
    }

    /**
     * External payloads merge into the same top-level namespace that holds
     * the engine's own checkpoints. A colliding "steps" key would replace
     * the whole subtree — forged agent outputs, planted approval decisions,
     * reset evaluate counters — so it is reserved.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function guardReservedKeys(array $payload): void
    {
        if (array_key_exists('steps', $payload)) {
            throw new WorkflowException(
                'Payload key [steps] is reserved for engine checkpoints and cannot be merged into run state. '.
                'Whitelist the fields you accept — never pass raw request input.'
            );
        }
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

            $update = [
                'status' => RunStatus::Cancelled,
                'finished_at' => now(),
            ];

            if (static::schemaHasKeyColumns()) {
                $update['active_key'] = null;
                // Cancelling a settled failed run changes its outcome —
                // clear the stamp so the next settle delivers it, the same
                // contract retry() honors.
                $update['settled_at'] = null;
            }

            $run->update($update);
        });

        event(new WorkflowCancelled($this->refresh()));

        app(GroupSettler::class)->settle($this->group_key);

        return $this;
    }

    /**
     * Re-dispatch a failed run from its checkpoint. Only the failed step is
     * re-executed — every step before it keeps its committed result.
     */
    public function retry(): static
    {
        try {
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

                // The run is active again: re-claim its singleton key (the
                // unique index throws when another run holds it now) and
                // clear settled_at so a group delivers this run's new
                // outcome in the following settle.
                $update = [
                    'status' => RunStatus::Pending,
                    'failed_step' => null,
                    'failure_reason' => null,
                    'finished_at' => null,
                ];

                if (static::schemaHasKeyColumns()) {
                    $update['active_key'] = $run->key;
                    $update['settled_at'] = null;
                }

                $run->update($update);

                return $step;
            });
        } catch (UniqueConstraintViolationException) {
            throw new WorkflowException(
                "Run [{$this->id}] cannot be retried: another active run of [{$this->name}] now holds key [{$this->key}]."
            );
        }

        WorkflowStepJob::dispatch($this->id, $step)->afterCommit();

        return $this->refresh();
    }
}
