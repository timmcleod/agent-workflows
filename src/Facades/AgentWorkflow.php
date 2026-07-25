<?php

namespace TimMcLeod\AgentWorkflows\Facades;

use Illuminate\Support\Facades\Facade;
use TimMcLeod\AgentWorkflows\Testing\WorkflowFake;
use TimMcLeod\AgentWorkflows\WorkflowManager;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

/**
 * @method static \TimMcLeod\AgentWorkflows\WorkflowDefinition register(\TimMcLeod\AgentWorkflows\Workflow|string $workflow)
 * @method static \TimMcLeod\AgentWorkflows\Models\WorkflowRun start(string $name, array<string, mixed> $input = [], ?object $participant = null)
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
        $fake = new WorkflowFake(app(WorkflowRegistry::class));

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
