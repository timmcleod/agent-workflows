<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;
use Stringable;

class RefundsAgent implements Agent, RemembersConversationsContract
{
    use Promptable, RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'Handle refund requests.';
    }

    public function handoffDescription(): string
    {
        return 'Transfer to the refunds specialist for anything refund-related.';
    }
}
