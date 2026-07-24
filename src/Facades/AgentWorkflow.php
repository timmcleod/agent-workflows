<?php

namespace TimMcLeod\AgentWorkflows\Facades;

use Illuminate\Support\Facades\Facade;
use TimMcLeod\AgentWorkflows\WorkflowManager;

/**
 * @method static \TimMcLeod\AgentWorkflows\WorkflowDefinition define(string $name)
 * @method static \TimMcLeod\AgentWorkflows\Models\WorkflowRun start(string $name, array<string, mixed> $input = [], ?object $participant = null)
 *
 * @see WorkflowManager
 */
class AgentWorkflow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WorkflowManager::class;
    }
}
