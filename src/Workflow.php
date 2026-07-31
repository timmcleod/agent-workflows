<?php

namespace TimMcLeod\AgentWorkflows;

use Illuminate\Support\Str;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

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

    /**
     * The state class hydrated for every step, prompt closure, condition,
     * and predicate of this workflow. Override with a WorkflowState subclass
     * to give the run's state typed, semantic accessors; the underlying
     * storage and checkpoint format are unchanged.
     *
     * @return class-string<WorkflowState>
     */
    public function stateClass(): string
    {
        return WorkflowState::class;
    }

    public function definition(): WorkflowDefinition
    {
        return $this->build(new WorkflowDefinition($this->name(), $this->stateClass()));
    }

    /**
     * Start a new run of this workflow.
     *
     * @param  array<string, mixed>  $input
     */
    public static function start(array $input = [], ?object $participant = null): WorkflowRun
    {
        return app(WorkflowManager::class)->start(static::class, $input, $participant);
    }
}
