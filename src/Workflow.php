<?php

namespace TimMcLeod\AgentWorkflows;

use Illuminate\Support\Str;

/**
 * Class-based workflow definition — the artisan-generatable alternative to
 * calling AgentWorkflow::define() in a service provider. Register these via
 * the "workflows" array in config/agent-workflows.php (so queue workers know
 * them too) or with AgentWorkflow::register().
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
