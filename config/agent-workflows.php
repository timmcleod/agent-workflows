<?php

return [

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
