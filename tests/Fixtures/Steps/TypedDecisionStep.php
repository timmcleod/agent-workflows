<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps;

use TimMcLeod\AgentWorkflows\Tests\Fixtures\States\ReviewState;
use TimMcLeod\AgentWorkflows\WorkflowState;

/**
 * A step typed against the workflow's custom state class. Records the class
 * it actually received so tests can assert on hydration.
 */
class TypedDecisionStep
{
    public function __invoke(ReviewState $state): WorkflowState
    {
        return $state
            ->set('received_class', $state::class)
            ->recordDecision($state->isHighRisk() ? 'escalate' : 'auto-approve');
    }
}
