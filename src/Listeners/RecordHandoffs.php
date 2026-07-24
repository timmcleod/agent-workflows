<?php

namespace TimMcLeod\AgentWorkflows\Listeners;

use Laravel\Ai\Events\AgentPrompted;
use TimMcLeod\AgentWorkflows\Contracts\HasHandoffs;
use TimMcLeod\AgentWorkflows\Handoffs\HandoffManager;
use TimMcLeod\AgentWorkflows\Handoffs\HandoffTool;

/**
 * Watches every agent response for calls to synthetic transfer_to_* tools
 * and records the conversation's new owner.
 */
class RecordHandoffs
{
    public function __construct(protected HandoffManager $handoffs) {}

    public function handle(AgentPrompted $event): void
    {
        $agent = $event->prompt->agent;
        $conversationId = $event->response->conversationId;

        if (! $agent instanceof HasHandoffs || $conversationId === null) {
            return;
        }

        $targets = [];

        foreach ($agent->handoffs() as $target) {
            $targets[HandoffTool::nameFor($target)] = $target;
        }

        foreach ($event->response->toolCalls as $call) {
            if (isset($targets[$call->name])) {
                $this->handoffs->transfer($conversationId, $targets[$call->name]);
            }
        }
    }
}
