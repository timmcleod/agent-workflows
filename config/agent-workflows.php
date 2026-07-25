<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Class-Based Workflows
    |--------------------------------------------------------------------------
    |
    | Workflow classes (created with `php artisan make:agent-workflow`) listed
    | here are registered on every process at boot — including queue workers,
    | which must know each definition to execute its steps.
    |
    */

    'workflows' => [
        // App\AgentWorkflows\ContractReview::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Connection & Queue
    |--------------------------------------------------------------------------
    |
    | Workflow steps execute as queued jobs. By default they use your
    | application's default queue connection and queue. Override here to
    | isolate agent workflows onto their own queue so long-running agent
    | steps don't starve your application's other jobs.
    |
    */

    'queue' => [
        'connection' => env('AGENT_WORKFLOWS_QUEUE_CONNECTION'),
        'queue' => env('AGENT_WORKFLOWS_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | The tables used to store workflow runs, the per-step audit log, and
    | pending interrupts. Change these before running the migrations if the
    | defaults collide with tables in your application.
    |
    */

    'tables' => [
        'runs' => 'agent_workflow_runs',
        'steps' => 'agent_workflow_steps',
        'interrupts' => 'agent_workflow_interrupts',
        'conversation_owners' => 'agent_conversation_owners',
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale-Run Sweeping
    |--------------------------------------------------------------------------
    |
    | The agent-workflows:sweep command (schedule it every few minutes)
    | recovers runs stranded by hard-killed workers or lost dispatches. A
    | pending/running run untouched for longer than stale_after seconds is
    | either re-dispatched from its checkpoint ("redispatch") or marked
    | failed ("fail"). Set stale_after comfortably above your longest step,
    | including parallel fan-outs.
    |
    */

    'sweep' => [
        'stale_after' => env('AGENT_WORKFLOWS_STALE_AFTER', 600),
        'action' => env('AGENT_WORKFLOWS_SWEEP_ACTION', 'redispatch'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Definition Drift
    |--------------------------------------------------------------------------
    |
    | Every run stores a hash of its workflow definition at start time. When
    | a run is resumed (or a step retried) after a deploy that changed the
    | definition, the stored hash will no longer match.
    |
    | "strict" — refuse to resume, throwing DefinitionDriftException.
    | "loose"  — resume best-effort by step name and log a warning.
    |
    */

    'definition_drift' => env('AGENT_WORKFLOWS_DEFINITION_DRIFT', 'strict'),

];
