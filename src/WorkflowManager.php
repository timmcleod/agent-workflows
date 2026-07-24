<?php

namespace TimMcLeod\AgentWorkflows;

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Jobs\WorkflowStepJob;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowManager
{
    public function __construct(protected WorkflowRegistry $registry) {}

    /**
     * Define (and register) a named workflow.
     */
    public function define(string $name): WorkflowDefinition
    {
        $definition = new WorkflowDefinition($name);

        $this->registry->register($definition);

        return $definition;
    }

    /**
     * Start a new run of the given workflow.
     *
     * @param  array<string, mixed>  $input
     */
    public function start(string $name, array $input = [], ?object $participant = null): WorkflowRun
    {
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

        WorkflowStepJob::dispatch($run->id, $first->id)->afterCommit();

        return $run->refresh();
    }
}
