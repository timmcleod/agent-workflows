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
 *
 * Definition-authored string prompts may carry {{ placeholder }} templates,
 * resolved here against the state bag. Closure results and the state's
 * "prompt" fallback are deliberately never interpolated: a closure already
 * has state access, and runtime-supplied text must not become a template.
 */
trait ResolvesPrompts
{
    /**
     * @param  Closure(WorkflowState): string|string|null  $source
     */
    protected function resolvePromptSource(Closure|string|null $source, WorkflowState $state, string $subject, string $failureMessage): string
    {
        $prompt = match (true) {
            $source instanceof Closure => $source($state),
            is_string($source) => $this->interpolatePrompt($source, $state, $subject),
            default => $state->get('prompt'),
        };

        if (! is_string($prompt) || $prompt === '') {
            throw new MissingWorkflowPromptException($failureMessage);
        }

        return $prompt;
    }

    /**
     * Replace {{ placeholder }} templates with values from the state bag:
     * dot paths ({{ contract }}, {{ steps.Extract.text }}) and the output:
     * sugar mirroring $state->output() ({{ output:StepId }} for the text,
     * {{ output:StepId.path }} for a structured key). Unresolvable
     * placeholders throw rather than prompting with a hole. There is no
     * escape syntax: "{{" cannot occur inside valid JSON, and the rare
     * prompt needing it literally should use a closure instead.
     */
    protected function interpolatePrompt(string $template, WorkflowState $state, string $subject): string
    {
        return preg_replace_callback(
            '/\{\{\s*([A-Za-z_][A-Za-z0-9_.:-]*)\s*\}\}/',
            function (array $match) use ($state, $subject): string {
                $value = $this->placeholderValue($match[1], $state);

                if ($value === null) {
                    throw new MissingWorkflowPromptException(
                        "{$subject} has a prompt placeholder {{ {$match[1]} }} that could not be resolved ".
                        'from the workflow state. Placeholders resolve state paths or output:StepId lookups; '.
                        'for a literal {{ in a prompt, use a closure prompt instead.'
                    );
                }

                return $this->stringifyPlaceholder($value);
            },
            $template,
        );
    }

    protected function placeholderValue(string $path, WorkflowState $state): mixed
    {
        if (! str_starts_with($path, 'output:')) {
            return $state->get($path);
        }

        [$stepId, $key] = array_pad(explode('.', substr($path, strlen('output:')), 2), 2, null);

        $output = $state->output($stepId);

        if ($output === null) {
            return null;
        }

        // Bare output: the text; structured-only agents fall back to the
        // whole structured array (JSON-encoded by the caller).
        return $key === null
            ? ($output->text() ?? $output->structured())
            : $output->structured($key);
    }

    protected function stringifyPlaceholder(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
