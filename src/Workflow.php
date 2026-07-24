<?php

namespace TimMcLeod\AgentWorkflows;

use Illuminate\Support\Str;

/**
 * A workflow definition. Generate with `php artisan make:agent-workflow`
 * and register via the "workflows" array in config/agent-workflows.php —
 * that runs on every process at boot, so queue workers know the definition
 * too. AgentWorkflow::register() exists for runtime registration (tests,
 * packages).
 */
abstract class Workflow
{
    /**
     * The workflow's registered name. Defaults to the kebab-cased class name
     * (ContractReview => "contract-review").
     */
    public function name(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /**
     * Build the workflow's steps.
     */
    abstract public function build(WorkflowDefinition $workflow): WorkflowDefinition;

    public function definition(): WorkflowDefinition
    {
        return $this->build(new WorkflowDefinition($this->name()));
    }
}
