<?php

namespace TimMcLeod\AgentWorkflows;

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
     * @param  string|class-string<Workflow>  $name
     * @param  array<string, mixed>  $input
     */
    public function start(string $name, array $input = [], ?object $participant = null): WorkflowRun
    {
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

        if ($participant !== null) {
            $run->participant()->associate($participant);
        }

        $run->save();

        event(new WorkflowStarted($run));

        WorkflowStepJob::dispatch($run->id, $first->id)->afterCommit();

        return $run->refresh();
    }
}
