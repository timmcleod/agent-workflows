<?php

namespace TimMcLeod\AgentWorkflows\Testing;

use Closure;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert as PHPUnit;
use TimMcLeod\AgentWorkflows\Events\StepCompleted;
use TimMcLeod\AgentWorkflows\Events\WorkflowCancelled;
use TimMcLeod\AgentWorkflows\Events\WorkflowCompleted;
use TimMcLeod\AgentWorkflows\Events\WorkflowFailed;
use TimMcLeod\AgentWorkflows\Events\WorkflowInterrupted;
use TimMcLeod\AgentWorkflows\Events\WorkflowResumed;
use TimMcLeod\AgentWorkflows\Events\WorkflowStarted;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Workflow;
use TimMcLeod\AgentWorkflows\WorkflowManager;

/**
 * Records workflow lifecycle events for assertions. Workflows still execute
 * (fake the agents themselves with the SDK's Agent::fake()), so assertions
 * can cover the full run: started, steps ran, completed or failed.
 */
class WorkflowFake extends WorkflowManager
{
    /** @var array<int, WorkflowStarted> */
    protected array $started = [];

    /** @var array<int, StepCompleted> */
    protected array $stepsCompleted = [];

    /** @var array<int, WorkflowCompleted> */
    protected array $completed = [];

    /** @var array<int, WorkflowFailed> */
    protected array $failed = [];

    /** @var array<int, WorkflowInterrupted> */
    protected array $interrupted = [];

    /** @var array<int, WorkflowResumed> */
    protected array $resumed = [];

    /** @var array<int, WorkflowCancelled> */
    protected array $cancelled = [];

    public function subscribe(): void
    {
        // The run model is cloned so assertions see its state at event time,
        // not whatever the live instance mutated into as the run progressed.
        Event::listen(WorkflowStarted::class, fn (WorkflowStarted $e) => $this->started[] = new WorkflowStarted(clone $e->run));
        Event::listen(StepCompleted::class, fn (StepCompleted $e) => $this->stepsCompleted[] = $e);
        Event::listen(WorkflowCompleted::class, fn (WorkflowCompleted $e) => $this->completed[] = $e);
        Event::listen(WorkflowFailed::class, fn (WorkflowFailed $e) => $this->failed[] = $e);
        Event::listen(WorkflowInterrupted::class, fn (WorkflowInterrupted $e) => $this->interrupted[] = $e);
        Event::listen(WorkflowResumed::class, fn (WorkflowResumed $e) => $this->resumed[] = $e);
        Event::listen(WorkflowCancelled::class, fn (WorkflowCancelled $e) => $this->cancelled[] = $e);
    }

    /**
     * @param  Closure(WorkflowRun): bool|null  $callback
     */
    public function assertStarted(string $name, ?Closure $callback = null): void
    {
        $name = $this->normalizeWorkflowName($name);

        $runs = $this->startedRuns($name);

        PHPUnit::assertNotEmpty($runs, "Workflow [{$name}] was not started.");

        if ($callback !== null) {
            PHPUnit::assertTrue(
                collect($runs)->contains(fn (WorkflowRun $run) => $callback($run)),
                "Workflow [{$name}] was started, but no run matched the given callback."
            );
        }
    }

    public function assertNotStarted(string $name): void
    {
        PHPUnit::assertEmpty(
            $this->startedRuns($this->normalizeWorkflowName($name)),
            "Workflow [{$name}] was started unexpectedly."
        );
    }

    public function assertNothingStarted(): void
    {
        PHPUnit::assertEmpty($this->started, 'Workflows were started unexpectedly.');
    }

    /**
     * Assert a step ran (completed) in some run. Accepts a step id or a step
     * class name (matched by its default id, the class basename).
     */
    public function assertStepRan(string $step): void
    {
        PHPUnit::assertContains(
            $this->normalizeStepId($step),
            $this->ranStepIds(),
            "Step [{$step}] did not run."
        );
    }

    public function assertStepDidNotRun(string $step): void
    {
        PHPUnit::assertNotContains(
            $this->normalizeStepId($step),
            $this->ranStepIds(),
            "Step [{$step}] ran unexpectedly."
        );
    }

    /**
     * Assert a step completed exactly $times times across all runs —
     * the assertion for "retried once", and for the engine's central
     * no-double-execution guarantee, which assertStepRan() cannot see
     * (it deduplicates).
     */
    public function assertStepRanTimes(string $step, int $times): void
    {
        $id = $this->normalizeStepId($step);

        $actual = collect($this->stepsCompleted)
            ->filter(fn (StepCompleted $e) => $e->step->step_id === $id)
            ->count();

        PHPUnit::assertSame(
            $times,
            $actual,
            "Step [{$step}] ran {$actual} time(s); expected {$times}."
        );
    }

    public function assertCompleted(string $name): void
    {
        $name = $this->normalizeWorkflowName($name);

        PHPUnit::assertTrue(
            collect($this->completed)->contains(fn (WorkflowCompleted $e) => $e->run->name === $name),
            "Workflow [{$name}] did not complete."
        );
    }

    public function assertFailed(string $name): void
    {
        $name = $this->normalizeWorkflowName($name);

        PHPUnit::assertTrue(
            collect($this->failed)->contains(fn (WorkflowFailed $e) => $e->run->name === $name),
            "Workflow [{$name}] did not fail."
        );
    }

    public function assertInterrupted(string $name, ?string $reason = null): void
    {
        $name = $this->normalizeWorkflowName($name);

        PHPUnit::assertTrue(
            collect($this->interrupted)->contains(function (WorkflowInterrupted $e) use ($name, $reason) {
                return $e->run->name === $name
                    && ($reason === null || $e->interrupt->reason === $reason);
            }),
            $reason === null
                ? "Workflow [{$name}] was not interrupted."
                : "Workflow [{$name}] was not interrupted with reason [{$reason}]."
        );
    }

    public function assertResumed(string $name): void
    {
        $name = $this->normalizeWorkflowName($name);

        PHPUnit::assertTrue(
            collect($this->resumed)->contains(fn (WorkflowResumed $e) => $e->run->name === $name),
            "Workflow [{$name}] was not resumed."
        );
    }

    public function assertCancelled(string $name): void
    {
        $name = $this->normalizeWorkflowName($name);

        PHPUnit::assertTrue(
            collect($this->cancelled)->contains(fn (WorkflowCancelled $e) => $e->run->name === $name),
            "Workflow [{$name}] was not cancelled."
        );
    }

    /**
     * @return array<int, WorkflowRun>
     */
    protected function startedRuns(string $name): array
    {
        return collect($this->started)
            ->map(fn (WorkflowStarted $e) => $e->run)
            ->filter(fn (WorkflowRun $run) => $run->name === $name)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function ranStepIds(): array
    {
        return collect($this->stepsCompleted)
            ->map(fn (StepCompleted $e) => $e->step->step_id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Workflow-name assertions accept a Workflow class name (like
     * start() does) or the registered string name.
     */
    protected function normalizeWorkflowName(string $name): string
    {
        return is_subclass_of($name, Workflow::class) ? app($name)->name() : $name;
    }

    protected function normalizeStepId(string $step): string
    {
        return class_exists($step) ? class_basename($step) : $step;
    }
}
