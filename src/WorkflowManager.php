<?php

namespace TimMcLeod\AgentWorkflows;

use Laravel\Ai\Contracts\Agent;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Events\WorkflowStarted;
use TimMcLeod\AgentWorkflows\Handoffs\HandoffManager;
use TimMcLeod\AgentWorkflows\Jobs\WorkflowStepJob;
use TimMcLeod\AgentWorkflows\Models\ConversationOwner;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

class WorkflowManager
{
    public function __construct(
        protected WorkflowRegistry $registry,
        protected HandoffManager $handoffs,
    ) {}

    /**
     * The agent that should handle the conversation's next turn: its
     * recorded owner (after a handoff), or the given default.
     *
     * @param  class-string<Agent>|null  $default
     */
    public function resolveAgentFor(string $conversationId, ?string $default = null): Agent
    {
        return $this->handoffs->resolveAgentFor($conversationId, $default);
    }

    /**
     * Manually transfer a conversation to another agent.
     *
     * @param  class-string<Agent>  $agent
     */
    public function transferConversation(string $conversationId, string $agent): ConversationOwner
    {
        return $this->handoffs->transfer($conversationId, $agent);
    }

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
