<?php

namespace TimMcLeod\AgentWorkflows;

use Illuminate\Database\UniqueConstraintViolationException;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Events\WorkflowStarted;
use TimMcLeod\AgentWorkflows\Jobs\WorkflowStepJob;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowManager
{
    public function __construct(protected WorkflowRegistry $registry) {}

    /**
     * Register a workflow. Workflows listed in the "workflows" config array
     * are registered automatically at boot; call this directly only for
     * runtime registration (tests, packages).
     *
     * @param  Workflow|class-string<Workflow>  $workflow
     */
    public function register(Workflow|string $workflow): WorkflowDefinition
    {
        if (is_string($workflow)) {
            $workflow = app($workflow);
        }

        $definition = $workflow->definition();

        $this->registry->register($definition);

        return $definition;
    }

    /**
     * Start a new run of the given workflow. Accepts a registered workflow
     * name or a class-based Workflow's class name.
     *
     * With a singleton $key (scoped per workflow name), start() is
     * idempotent: when an active run already holds the key, that run is
     * returned instead — no new run, no WorkflowStarted event, no step job.
     * `wasRecentlyCreated` tells callers which happened.
     *
     * With a $group, the run joins a run group (global, not scoped per
     * workflow name): when its last active member reaches a terminal
     * status, a WorkflowGroupSettled event delivers the group's terminal
     * runs exactly once each.
     *
     * @param  string|class-string<Workflow>  $name
     * @param  array<string, mixed>  $input
     */
    public function start(
        string $name,
        array $input = [],
        ?object $participant = null,
        ?string $key = null,
        ?string $group = null,
    ): WorkflowRun {
        if (! $this->registry->has($name) && is_subclass_of($name, Workflow::class)) {
            $name = $this->register($name)->name;
        }

        $definition = $this->registry->get($name);
        $first = $definition->firstStep();

        $run = new WorkflowRun([
            'name' => $name,
            'version' => $definition->hash(),
            'status' => RunStatus::Pending,
            'current_step' => $first->id,
            'state' => $input,
        ]);

        // Set only when used, so installs that have not run the columns'
        // migration keep working until they do (the features themselves
        // require it).
        if ($key !== null) {
            $run->key = $key;
            $run->active_key = $key;
        }

        if ($group !== null) {
            $run->group_key = $group;
        }

        if ($participant !== null) {
            $run->participant()->associate($participant);
        }

        // The unique (name, active_key) index is the singleton guard —
        // insert first, no check-then-act race. The insert runs in its own
        // (savepoint-wrapped when nested) transaction so a violation inside
        // a caller's transaction leaves that transaction usable for the
        // recovery fetch — Postgres aborts the whole transaction on a
        // statement error otherwise.
        try {
            $run->getConnection()->transaction(fn () => $run->save());
        } catch (UniqueConstraintViolationException $e) {
            if ($key === null) {
                throw $e;
            }

            $existing = WorkflowRun::query()
                ->where('name', $name)
                ->where('active_key', $key)
                ->first();

            // The holder can terminate in the instant between the violation
            // and this fetch. Rethrowing keeps model events exactly-once per
            // persisted run (retrying save() would re-fire creating on the
            // same instance); the caller's own retry of start() succeeds.
            if ($existing === null) {
                throw $e;
            }

            // The idempotent return adopts the requested group only when
            // the existing run has none — an established group is never
            // silently rewritten.
            if ($group !== null && $existing->group_key === null) {
                $existing->update(['group_key' => $group]);
            }

            return $existing;
        }

        event(new WorkflowStarted($run));

        WorkflowStepJob::dispatch($run->id, $first->id)->afterCommit();

        return $run->refresh();
    }
}
