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
    | isolate agent workflows onto their own queue (recommended with
    | Horizon so long-running agent steps don't starve other jobs).
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
