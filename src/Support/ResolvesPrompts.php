<?php

namespace TimMcLeod\AgentWorkflows\Support;

use Closure;
use TimMcLeod\AgentWorkflows\Exceptions\MissingWorkflowPromptException;
use TimMcLeod\AgentWorkflows\WorkflowState;

/**
 * The runtime rungs of the prompt ladder, shared by every step body that
 * prompts an agent. The definition-time rungs (explicit prompt, then the
 * workflow class's conventional {camel(stepId)}Prompt method) are compiled
 * into the step before it ever reaches an executor, so at this point a
 * closure or string IS the resolved prompt; only the state fallback and the
 * failure rung remain.
 */
trait ResolvesPrompts
{
    /**
     * @param  Closure(WorkflowState): string|string|null  $source
     */
    protected function resolvePromptSource(Closure|string|null $source, WorkflowState $state, string $failureMessage): string
    {
        $prompt = match (true) {
            $source instanceof Closure => $source($state),
            is_string($source) => $source,
            default => $state->get('prompt'),
        };

        if (! is_string($prompt) || $prompt === '') {
            throw new MissingWorkflowPromptException($failureMessage);
        }

        return $prompt;
    }
}
