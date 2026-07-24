<?php

namespace TimMcLeod\AgentWorkflows\Support;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Responses\AgentResponse;
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
        return $this->toResult($agent->prompt($prompt));
    }

    /**
     * Replay human decisions into an agent whose run paused on tool
     * approvals. Decision values follow the SDK's map shape: tool-call id =>
     * true/false (or Decision objects).
     *
     * @param  array<string, mixed>  $decisions
     */
    public function resumeApprovals(Agent $agent, ?string $conversationId, array $decisions): AgentStepResult
    {
        // Real paused runs always have a conversation (the SDK requires it
        // to pause); faked responses may not, and fakes skip replay anyway.
        if ($conversationId !== null && $agent instanceof RemembersConversations) {
            $agent = $agent->continue($conversationId);
        }

        return $this->toResult($agent->prompt(Decisions::from($decisions)));
    }

    protected function toResult(AgentResponse $response): AgentStepResult
    {
        return new AgentStepResult(
            text: $response->text,
            structured: $response instanceof StructuredAgentResponse ? $response->structured : null,
            usage: $response->usage->toArray(),
            conversationId: $response->conversationId,
            pendingApprovals: $response->pendingApprovals
                ->map(fn (PendingApproval $approval) => $approval->toArray())
                ->all(),
        );
    }
}
