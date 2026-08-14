<?php

namespace TimMcLeod\AgentWorkflows\Steps;

use TimMcLeod\AgentWorkflows\Interrupts\PendingInterrupt;
use TimMcLeod\AgentWorkflows\WorkflowState;

class StepResult
{
    /**
     * @param  array<string, int>|null  $usage
     * @param  PendingInterrupt|null  $interrupt  set when the step asks to
     *                                            park the run instead of completing
     * @param  array<int, array<string, mixed>>  $calls  per-provider-call audit
     *                                                   detail, in call order
     */
    public function __construct(
        public readonly WorkflowState $state,
        public readonly ?array $usage = null,
        public readonly ?PendingInterrupt $interrupt = null,
        public readonly array $calls = [],
    ) {}
}
