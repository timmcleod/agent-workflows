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
     * Start a new run of this workflow. With a singleton $key, returns the
     * existing active run instead when one already holds the key; with a
     * $group, the run joins a run group that settles when its last active
     * member finishes. See WorkflowManager::start().
     *
     * Final on purpose: this is an entry point, not an override point — its
     * parameter list may grow in minor releases, and PHP rejects subclass
     * overrides whose signatures fall behind. Wrap it in your own named
     * method instead.
     *
     * @param  array<string, mixed>  $input
     */
    final public static function start(
        array $input = [],
        ?object $participant = null,
        ?string $key = null,
        ?string $group = null,
    ): WorkflowRun {
        return app(WorkflowManager::class)->start(static::class, $input, $participant, $key, $group);
    }
}
