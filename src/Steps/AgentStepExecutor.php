<?php

namespace TimMcLeod\AgentWorkflows\Steps;

use Illuminate\Contracts\Container\Container;
use Laravel\Ai\Contracts\Agent;
use TimMcLeod\AgentWorkflows\Contracts\HasWorkflowPrompt;
use TimMcLeod\AgentWorkflows\Exceptions\MissingWorkflowPromptException;
use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\Support\AgentAdapter;
use TimMcLeod\AgentWorkflows\WorkflowState;

class AgentStepExecutor implements StepExecutor
{
    public function __construct(
        protected Container $container,
        protected AgentAdapter $adapter,
    ) {}

    public function execute(StepDefinition $step, WorkflowState $state): StepResult
    {
        /** @var Agent $agent */
        $agent = $this->container->make($step->target);

        $result = $this->adapter->prompt($agent, $this->promptFor($agent, $step, $state));

        $state->set('steps.'.$step->id, array_filter([
            'text' => $result->text,
            'structured' => $result->structured,
            'conversation_id' => $result->conversationId,
        ], fn (mixed $value) => $value !== null && $value !== ''));

        return new StepResult($state, $result->usage);
    }

    protected function promptFor(Agent $agent, StepDefinition $step, WorkflowState $state): string
    {
        if ($agent instanceof HasWorkflowPrompt) {
            return $agent->workflowPrompt($state);
        }

        $prompt = $state->get('prompt');

        if (! is_string($prompt) || $prompt === '') {
            throw new MissingWorkflowPromptException(
                "Agent step [{$step->id}] needs a prompt: implement ".HasWorkflowPrompt::class.
                " on [{$step->target}] or provide a string under the state's \"prompt\" key."
            );
        }

        return $prompt;
    }
}
