<?php

namespace TimMcLeod\AgentWorkflows\Steps;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use TimMcLeod\AgentWorkflows\Enums\InterruptType;
use TimMcLeod\AgentWorkflows\Interrupts\PendingInterrupt;
use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\Support\AgentAdapter;
use TimMcLeod\AgentWorkflows\Support\AgentStepResult;
use TimMcLeod\AgentWorkflows\Support\ResolvesPrompts;
use TimMcLeod\AgentWorkflows\WorkflowState;

class AgentStepExecutor implements StepExecutor
{
    use ResolvesPrompts;

    public function __construct(
        protected Container $container,
        protected AgentAdapter $adapter,
    ) {}

    public function execute(StepDefinition $step, WorkflowState $state): StepResult
    {
        /** @var Agent $agent */
        $agent = $this->container->make($step->target);

        $key = 'steps.'.$step->id;

        // Approval resume: resume() stored the human's decisions in state, so
        // a crash before this step completes replays them on retry too.
        if ($state->has($key.'.approval_decisions')) {
            $result = $this->adapter->resumeApprovals(
                $agent,
                $state->get($key.'.conversation_id'),
                $state->get($key.'.approval_decisions'),
            );

            $state->forget($key.'.approval_decisions');
        } else {
            $result = $this->adapter->prompt($agent, $this->promptFor($step, $state));
        }

        // The SDK paused on tool approvals — surface it as a workflow
        // interrupt instead of completing the step.
        if ($result->hasPendingApprovals()) {
            $state->set($key.'.conversation_id', $result->conversationId);

            return new StepResult($state, $result->usage, new PendingInterrupt(
                type: InterruptType::Approval,
                reason: $this->approvalReason($step, $result),
                context: ['approvals' => $result->pendingApprovals],
            ), $result->calls);
        }

        $state->set($key, array_filter([
            // For structured agents the text is just the raw JSON of the
            // same data — checkpoint only the structured form.
            'text' => $result->structured === null ? $result->text : null,
            'structured' => $result->structured,
            'conversation_id' => $result->conversationId,
        ], fn (mixed $value) => $value !== null && $value !== ''));

        return new StepResult($state, $result->usage, calls: $result->calls);
    }

    protected function approvalReason(StepDefinition $step, AgentStepResult $result): string
    {
        $tools = implode(', ', array_column($result->pendingApprovals, 'tool'));

        return "Agent step [{$step->id}] is awaiting approval for: {$tools}";
    }

    protected function promptFor(StepDefinition $step, WorkflowState $state): string
    {
        return $this->resolvePromptSource(
            $step->prompt,
            $state,
            "Agent step [{$step->id}] needs a prompt: pass prompt: when defining the step, ".
            'define a '.Str::camel($step->id).'Prompt() method on the workflow class, '.
            'or provide a string under the state\'s "prompt" key.'
        );
    }
}
