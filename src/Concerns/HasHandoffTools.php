<?php

namespace TimMcLeod\AgentWorkflows\Concerns;

use TimMcLeod\AgentWorkflows\Contracts\HasHandoffs;
use TimMcLeod\AgentWorkflows\Handoffs\HandoffTool;

/**
 * Include the returned tools from your agent's tools() method:
 *
 *     public function tools(): iterable
 *     {
 *         return [...$this->handoffTools(), new LookupOrderTool];
 *     }
 */
trait HasHandoffTools
{
    /**
     * @return array<int, HandoffTool>
     */
    public function handoffTools(): array
    {
        if (! $this instanceof HasHandoffs) {
            return [];
        }

        return array_map(
            fn (string $target) => new HandoffTool($target),
            $this->handoffs(),
        );
    }
}
