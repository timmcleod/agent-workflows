<?php

namespace TimMcLeod\AgentWorkflows\Runtime;

use Illuminate\Support\Facades\Log;
use TimMcLeod\AgentWorkflows\Exceptions\DefinitionDriftException;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

/**
 * Enforces the definition-drift policy wherever a run executes against a
 * definition resolved from the registry: cursor steps, parallel branch
 * jobs, and the batch completer. A run stores its definition's hash at
 * start time; a mismatch means a deploy changed the workflow mid-run.
 */
class DriftGuard
{
    public function check(WorkflowRun $run, WorkflowDefinition $definition, string $stepId): void
    {
        if ($run->version === $definition->hash()) {
            return;
        }

        if (config('agent-workflows.definition_drift') === 'strict') {
            throw new DefinitionDriftException(
                "Workflow [{$run->name}] definition has changed since run [{$run->id}] started. ".
                'Set agent-workflows.definition_drift to "loose" to resume best-effort by step name.'
            );
        }

        if (! $definition->hasStep($stepId)) {
            throw new DefinitionDriftException(
                "Workflow [{$run->name}] definition has changed and step [{$stepId}] no longer exists."
            );
        }

        Log::warning("Agent workflow run [{$run->id}] is resuming on a drifted definition of [{$run->name}].");
    }
}
