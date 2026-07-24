<?php

namespace TimMcLeod\AgentWorkflows;

use InvalidArgumentException;

class WorkflowRegistry
{
    /** @var array<string, WorkflowDefinition> */
    protected array $definitions = [];

    public function register(WorkflowDefinition $definition): void
    {
        $this->definitions[$definition->name] = $definition;
    }

    public function get(string $name): WorkflowDefinition
    {
        if (! isset($this->definitions[$name])) {
            throw new InvalidArgumentException("Agent workflow [{$name}] is not defined.");
        }

        return $this->definitions[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }
}
