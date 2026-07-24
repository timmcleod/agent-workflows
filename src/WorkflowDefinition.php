<?php

namespace TimMcLeod\AgentWorkflows;

use Closure;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;

class WorkflowDefinition
{
    /** @var array<int, StepDefinition> */
    protected array $steps = [];

    /** @var array<int, string> */
    protected array $reservedIds = [];

    public function __construct(public readonly string $name) {}

    /**
     * Define the first step of the workflow.
     *
     * @param  class-string  $target
     */
    public function start(string $target, ?string $as = null): static
    {
        return $this->then($target, $as);
    }

    /**
     * Append a sequential step. Agent classes become agent steps; any other
     * invokable class becomes a callback step.
     *
     * @param  class-string  $target
     */
    public function then(string $target, ?string $as = null): static
    {
        $this->steps[] = $this->makeStep($target, $as);

        return $this;
    }

    /**
     * Branch at runtime: when the condition holds, run $then, otherwise run
     * $else (or skip straight to the next step when $else is omitted). The
     * workflow continues sequentially after whichever branch ran.
     *
     * @param  Closure(WorkflowState): bool  $condition
     * @param  class-string  $then
     * @param  class-string|null  $else
     */
    public function when(Closure $condition, string $then, ?string $else = null, ?string $as = null): static
    {
        $whenTrue = $this->makeStep($then);
        $whenFalse = $else !== null ? $this->makeStep($else) : null;

        $this->steps[] = new ConditionStepDefinition(
            $this->stepId($as ?? 'when:'.(count($this->steps) + 1)),
            $condition,
            $whenTrue,
            $whenFalse,
        );

        return $this;
    }

    /**
     * Fan out into concurrent branches, each starting from the same state
     * snapshot, then merge the branch states and continue.
     *
     * Modes: "batch" (default) runs branches as a queued Bus::batch — the
     * durable option; "sync" runs them in-process via Concurrency::run().
     *
     * @param  array<int|string, class-string>  $targets  string keys become step aliases
     * @param  Closure(array<string, array<string, mixed>>, array<string, mixed>): (WorkflowState|array<string, mixed>)|null  $merge
     */
    public function parallel(array $targets, ?Closure $merge = null, string $mode = 'batch', ?string $as = null): static
    {
        if (! in_array($mode, ['batch', 'sync'], true)) {
            throw new InvalidArgumentException("Parallel mode must be \"batch\" or \"sync\", [{$mode}] given.");
        }

        if ($targets === []) {
            throw new InvalidArgumentException('A parallel step needs at least one branch.');
        }

        $branches = [];

        foreach ($targets as $key => $target) {
            $branches[] = $this->makeStep($target, is_string($key) ? $key : null);
        }

        $this->steps[] = new ParallelStepDefinition(
            $this->stepId($as ?? 'parallel:'.(count($this->steps) + 1)),
            $branches,
            $merge,
            $mode,
        );

        return $this;
    }

    /**
     * Evaluator-optimizer loop: run the target repeatedly until the predicate
     * holds (or maxIterations is reached), checkpointing every iteration.
     *
     * @param  class-string  $target
     * @param  Closure(WorkflowState): bool  $until
     */
    public function evaluate(string $target, Closure $until, int $maxIterations = 3, ?string $as = null): static
    {
        if ($maxIterations < 1) {
            throw new InvalidArgumentException('maxIterations must be at least 1.');
        }

        $id = $this->stepId($as ?? 'evaluate:'.class_basename($target));

        // The body deliberately shares the evaluate step's id (see EvaluateStepDefinition).
        $body = new StepDefinition($id, $this->typeFor($target), $target);

        $this->steps[] = new EvaluateStepDefinition($id, $body, $until, $maxIterations);

        return $this;
    }

    /**
     * Pause the run until a human resumes it. The run is persisted as
     * awaiting_human with the reason and an optional response schema
     * (Laravel validation rules); resume() validates the human's payload
     * against the schema and merges it into state.
     *
     * @param  array<string, mixed>|null  $schema
     */
    public function awaitHuman(?string $reason = null, ?array $schema = null, ?string $as = null): static
    {
        $this->steps[] = new AwaitHumanStepDefinition(
            $this->stepId($as ?? 'await-human:'.(count($this->steps) + 1)),
            $reason,
            $schema,
        );

        return $this;
    }

    /**
     * Pause the run until a named application event is delivered via
     * $run->deliverEvent($event, $payload).
     */
    public function awaitEvent(string $event, ?string $as = null): static
    {
        $this->steps[] = new AwaitEventStepDefinition(
            $this->stepId($as ?? 'await-event:'.$event),
            $event,
        );

        return $this;
    }

    /**
     * @return array<int, StepDefinition>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function step(string $id): StepDefinition
    {
        foreach ($this->allSteps() as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        throw new WorkflowException("Workflow [{$this->name}] has no step [{$id}].");
    }

    public function hasStep(string $id): bool
    {
        foreach ($this->allSteps() as $step) {
            if ($step->id === $id) {
                return true;
            }
        }

        return false;
    }

    public function firstStep(): StepDefinition
    {
        if ($this->steps === []) {
            throw new WorkflowException("Workflow [{$this->name}] has no steps.");
        }

        return $this->steps[0];
    }

    /**
     * The step that follows the given step, or null if it is the last. For a
     * step nested inside a condition, the successor is whatever follows the
     * condition itself.
     */
    public function after(string $id): ?StepDefinition
    {
        foreach ($this->steps as $index => $step) {
            if ($step->id === $id) {
                return $this->steps[$index + 1] ?? null;
            }
        }

        foreach ($this->steps as $step) {
            foreach ($step->children() as $child) {
                if ($child->id === $id) {
                    return $this->after($step->id);
                }
            }
        }

        throw new WorkflowException("Workflow [{$this->name}] has no step [{$id}].");
    }

    /**
     * A stable hash of the definition, stored on each run at start time so
     * definition drift across deploys can be detected on resume. Closure
     * bodies (conditions, predicates, merge strategies) are not hashed —
     * only the step structure is.
     */
    public function hash(): string
    {
        $fingerprint = [
            'name' => $this->name,
            'steps' => array_map(fn (StepDefinition $step) => $step->fingerprint(), $this->steps),
        ];

        return hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  class-string  $target
     */
    protected function makeStep(string $target, ?string $as = null): StepDefinition
    {
        return new StepDefinition($this->stepId($as ?? $target), $this->typeFor($target), $target);
    }

    /**
     * @param  class-string  $target
     */
    protected function typeFor(string $target): StepType
    {
        return is_a($target, Agent::class, true) ? StepType::Agent : StepType::Callback;
    }

    /**
     * Every step in the definition, including nested branch steps.
     *
     * @return array<int, StepDefinition>
     */
    protected function allSteps(): array
    {
        $all = [];

        foreach ($this->steps as $step) {
            $all[] = $step;

            foreach ($step->children() as $child) {
                $all[] = $child;
            }
        }

        return $all;
    }

    /**
     * Derive a unique, readable step id from the target class (or explicit
     * alias), reserving it so steps built before being appended (branch
     * steps) still collide-check against each other.
     */
    protected function stepId(string $base): string
    {
        $id = class_exists($base) ? class_basename($base) : $base;

        $candidate = $id;
        $suffix = 2;

        while (in_array($candidate, $this->reservedIds, true)) {
            $candidate = $id.':'.$suffix++;
        }

        $this->reservedIds[] = $candidate;

        return $candidate;
    }
}
