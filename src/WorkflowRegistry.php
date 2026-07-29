<?php

namespace TimMcLeod\AgentWorkflows;

use InvalidArgumentException;

class WorkflowRegistry
{
    /** @var array<string, WorkflowDefinition> */
    protected array $definitions = [];

    public function register(WorkflowDefinition $definition): void
    {
        $existing = $this->definitions[$definition->name] ?? null;

        // Re-registering the identical definition is a no-op (idempotent
        // boot); a DIFFERENT definition under an existing name is almost
        // always two workflow classes kebab-casing to the same name — and
        // silently letting boot order pick the winner would route every
        // run of the loser through the wrong steps.
        if ($existing !== null && $existing->hash() !== $definition->hash()) {
            throw new InvalidArgumentException(
                "Agent workflow [{$definition->name}] is already registered with a different definition. ".
                'Rename one workflow (override name()), or forget() the old definition first if replacing it is intended.'
            );
        }

        $this->definitions[$definition->name] = $definition;
    }

    /**
     * Drop a registered definition so a different one can take its name —
     * the explicit escape hatch for tests that simulate deploys.
     */
    public function forget(string $name): void
    {
        unset($this->definitions[$name]);
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
