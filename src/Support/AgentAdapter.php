<?php

namespace TimMcLeod\AgentWorkflows\Support;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * The single seam between this package and laravel/ai's prompting API.
 *
 * The SDK is on a fast-moving 0.x line with breaking changes in most minors;
 * everything the engine needs from an agent response is projected here into
 * AgentStepResult so SDK churn is absorbed in this one class.
 */
class AgentAdapter
{
    public function prompt(Agent $agent, string $prompt): AgentStepResult
    {
        $response = $agent->prompt($prompt);

        return new AgentStepResult(
            text: $response->text,
            structured: $response instanceof StructuredAgentResponse ? $response->structured : null,
            usage: $response->usage->toArray(),
            conversationId: $response->conversationId,
        );
    }
}
