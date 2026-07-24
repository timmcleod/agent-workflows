<?php

namespace TimMcLeod\AgentWorkflows\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Agent;

/**
 * Records which agent class currently owns an SDK conversation, so the next
 * user turn can be routed to the right agent after a handoff.
 *
 * @property int $id
 * @property string $conversation_id
 * @property class-string<Agent> $agent
 */
class ConversationOwner extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('agent-workflows.tables.conversation_owners', 'agent_conversation_owners');
    }
}
