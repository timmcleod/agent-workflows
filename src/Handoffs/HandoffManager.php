<?php

namespace TimMcLeod\AgentWorkflows\Handoffs;

use Laravel\Ai\Contracts\Agent;
use TimMcLeod\AgentWorkflows\Events\ConversationTransferred;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Models\ConversationOwner;

class HandoffManager
{
    /**
     * Record the given agent class as the conversation's owner.
     *
     * @param  class-string<Agent>  $agent
     */
    public function transfer(string $conversationId, string $agent): ConversationOwner
    {
        $previous = $this->ownerOf($conversationId);

        $owner = ConversationOwner::query()->updateOrCreate(
            ['conversation_id' => $conversationId],
            ['agent' => $agent],
        );

        event(new ConversationTransferred($conversationId, $agent, $previous));

        return $owner;
    }

    /**
     * @return class-string<Agent>|null
     */
    public function ownerOf(string $conversationId): ?string
    {
        return ConversationOwner::query()
            ->where('conversation_id', $conversationId)
            ->value('agent');
    }

    /**
     * Resolve the agent instance that should handle the conversation's next
     * turn: its recorded owner, or the default when no handoff has happened.
     *
     * @param  class-string<Agent>|null  $default
     */
    public function resolveAgentFor(string $conversationId, ?string $default = null): Agent
    {
        $class = $this->ownerOf($conversationId) ?? $default;

        if ($class === null) {
            throw new WorkflowException(
                "No agent owns conversation [{$conversationId}] and no default agent was given."
            );
        }

        return app($class);
    }
}
