<?php

namespace TimMcLeod\AgentWorkflows\Support;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
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
            invocationId: $response->invocationId,
            calls: $this->projectCalls($response),
        );
    }

    /**
     * The SDK's own record of the run's tool loop, projected for the audit
     * log: one entry per provider call, in call order. Entries share the
     * run's invocation id, so multi-call step bodies (the debate round) stay
     * attributable per prompt after their audits are concatenated. Tool
     * arguments and results are included only under the "full" audit mode,
     * since they can be large and can carry sensitive input.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function projectCalls(AgentResponse $response): array
    {
        $full = config('agent-workflows.audit.step_calls', 'full') === 'full';

        // Hand-built fake responses (paused-approval fakes) never pass
        // through the SDK's generation loop; guard the uninitialized case.
        if (! isset($response->steps)) {
            return [];
        }

        return $response->steps->map(fn (Step $step) => array_filter([
            'invocation_id' => $response->invocationId,
            'provider' => $step->meta->provider,
            'model' => $step->meta->model,
            'finish_reason' => $step->finishReason->value,
            'usage' => $step->usage->toArray(),
            'tool_calls' => array_map(fn (ToolCall $call) => array_filter([
                'id' => $call->id,
                'name' => $call->name,
                'arguments' => $full ? $call->arguments : null,
            ], fn (mixed $value) => $value !== null), $step->toolCalls),
            'tool_results' => array_map(fn (ToolResult $result) => array_filter([
                'id' => $result->id,
                'name' => $result->name,
                'result' => $full ? $result->result : null,
                'denied' => $result->denied ?: null,
            ], fn (mixed $value) => $value !== null), $step->toolResults),
        ], fn (mixed $value) => $value !== null && $value !== []))->values()->all();
    }
}
