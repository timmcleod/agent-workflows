<?php

namespace TimMcLeod\AgentWorkflows\Events;

use Laravel\Ai\Contracts\Agent;

class ConversationTransferred
{
    /**
     * @param  class-string<Agent>  $agent  the new owner
     * @param  class-string<Agent>|null  $previous  the prior owner, if any
     */
    public function __construct(
        public string $conversationId,
        public string $agent,
        public ?string $previous = null,
    ) {}
}
