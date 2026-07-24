<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps;

use RuntimeException;
use TimMcLeod\AgentWorkflows\WorkflowState;

class FlakyStep
{
    public static bool $fail = false;

    public function __invoke(WorkflowState $state): WorkflowState
    {
        if (static::$fail) {
            throw new RuntimeException('Flaky step exploded.');
        }

        return $state->set('sequence', [...$state->get('sequence', []), 'flaky']);
    }
}
