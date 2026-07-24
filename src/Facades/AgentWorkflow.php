<?php

namespace TimMcLeod\AgentWorkflows\Facades;

use Illuminate\Support\Facades\Facade;
use TimMcLeod\AgentWorkflows\Handoffs\HandoffManager;
use TimMcLeod\AgentWorkflows\Testing\WorkflowFake;
use TimMcLeod\AgentWorkflows\WorkflowManager;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

/**
 * @method static \TimMcLeod\AgentWorkflows\WorkflowDefinition define(string $name)
 * @method static \TimMcLeod\AgentWorkflows\WorkflowDefinition register(\TimMcLeod\AgentWorkflows\Workflow|string $workflow)
 * @method static \TimMcLeod\AgentWorkflows\Models\WorkflowRun start(string $name, array<string, mixed> $input = [], ?object $participant = null)
 * @method static \Laravel\Ai\Contracts\Agent resolveAgentFor(string $conversationId, ?string $default = null)
 * @method static \TimMcLeod\AgentWorkflows\Models\ConversationOwner transferConversation(string $conversationId, string $agent)
 *
 * @see WorkflowManager
 */
class AgentWorkflow extends Facade
{
    /**
     * Record workflow lifecycle events for assertions. Workflows still
     * execute — fake the agents themselves with the SDK's Agent::fake().
     */
    public static function fake(): WorkflowFake
    {
        $fake = new WorkflowFake(app(WorkflowRegistry::class), app(HandoffManager::class));

        $fake->subscribe();

        static::swap($fake);
        app()->instance(WorkflowManager::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return WorkflowManager::class;
    }
}
