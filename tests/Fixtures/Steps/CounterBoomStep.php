<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps;

use RuntimeException;
use TimMcLeod\AgentWorkflows\WorkflowState;

/**
 * Performs a non-transactional side effect (the counter) BEFORE optionally
 * crashing — the shape that proves at-least-once execution semantics.
 */
class CounterBoomStep
{
    public static int $count = 0;

    public static bool $fail = false;

    public function __invoke(WorkflowState $state): WorkflowState
    {
        static::$count++;

        if (static::$fail) {
            throw new RuntimeException('Boom after side effect.');
        }

        return $state->set('counted', static::$count);
    }
}
