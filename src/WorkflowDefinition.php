<?php

namespace TimMcLeod\AgentWorkflows;

use Laravel\Ai\Contracts\Agent;
use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;

class WorkflowDefinition
{
    /** @var array<int, StepDefinition> */
    protected array $steps = [];

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
        $type = is_a($target, Agent::class, true) ? StepType::Agent : StepType::Callback;

        $this->steps[] = new StepDefinition($this->stepId($as ?? $target), $type, $target);

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
        foreach ($this->steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        throw new WorkflowException("Workflow [{$this->name}] has no step [{$id}].");
    }

    public function hasStep(string $id): bool
    {
        foreach ($this->steps as $step) {
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
     * The step that follows the given step, or null if it is the last.
     */
    public function after(string $id): ?StepDefinition
    {
        foreach ($this->steps as $index => $step) {
            if ($step->id === $id) {
                return $this->steps[$index + 1] ?? null;
            }
        }

        throw new WorkflowException("Workflow [{$this->name}] has no step [{$id}].");
    }

    /**
     * A stable hash of the definition, stored on each run at start time so
     * definition drift across deploys can be detected on resume.
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
     * Derive a unique, readable step id from the target class (or explicit alias).
     */
    protected function stepId(string $base): string
    {
        $id = class_exists($base) ? class_basename($base) : $base;

        $candidate = $id;
        $suffix = 2;

        while ($this->hasStep($candidate)) {
            $candidate = $id.':'.$suffix++;
        }

        return $candidate;
    }
}
