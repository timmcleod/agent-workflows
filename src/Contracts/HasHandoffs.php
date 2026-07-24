<?php

namespace TimMcLeod\AgentWorkflows\Contracts;

use Laravel\Ai\Contracts\Agent;

/**
 * Implement on an SDK agent that can hand its conversation off to other
 * agents. Combine with the HasHandoffTools trait to expose one synthetic
 * transfer_to_* tool per target.
 */
interface HasHandoffs
{
    /**
     * The agent classes this agent may transfer its conversation to.
     *
     * @return array<int, class-string<Agent>>
     */
    public function handoffs(): array;
}
