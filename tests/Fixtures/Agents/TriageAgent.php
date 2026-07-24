<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;
use Stringable;
use TimMcLeod\AgentWorkflows\Concerns\HasHandoffTools;
use TimMcLeod\AgentWorkflows\Contracts\HasHandoffs;

class TriageAgent implements Agent, HasHandoffs, HasTools, RemembersConversationsContract
{
    use HasHandoffTools, Promptable, RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'Triage the customer request and transfer to a specialist when appropriate.';
    }

    public function handoffs(): array
    {
        return [RefundsAgent::class];
    }

    public function tools(): iterable
    {
        return $this->handoffTools();
    }
}
