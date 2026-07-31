# Runs, events, and observability

Everything a run does is queryable three ways: the Eloquent models, the lifecycle events, and the dashboard.

## Inspecting runs

Runs, steps, and interrupts are plain Eloquent models:

```php
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

$run = WorkflowRun::find($id);

$run->status;          // pending | running | awaiting_human | awaiting_event | failed | completed | cancelled
$run->current_step;    // the cursor
$run->state;           // the latest checkpoint (array)
$run->steps;           // audit log: every attempt of every step, with
                       // input-state hash, output-state snapshot, token usage,
                       // timings, and errors
```

Associate a run with a user (or any model) via the polymorphic participant:

```php
AgentWorkflow::start('contract-review', input: [...], participant: $user);
```

## Events

Listen for lifecycle events anywhere you'd listen for any Laravel event:

```php
use TimMcLeod\AgentWorkflows\Events\WorkflowFailed;

Event::listen(WorkflowFailed::class, function (WorkflowFailed $event) {
    Notification::send($ops, new WorkflowFailedNotification($event->run, $event->exception));
});
```

The full set, all under `TimMcLeod\AgentWorkflows\Events`:

- `WorkflowStarted($run)` — a run was created.
- `StepCompleted($run, $step)` — fires per step, including parallel branches, and carries the audit row (token usage included), which makes cost accounting a one-listener job.
- `WorkflowInterrupted($run, $interrupt)` — the run parked: `awaitHuman()`, `awaitEvent()`, or an agent tool-approval pause. The interrupt carries the reason, response schema, and context.
- `WorkflowResumed($run, $interrupt)` — a human resumed it or the awaited event arrived.
- `WorkflowCompleted($run)` / `WorkflowFailed($run, $exception)` / `WorkflowCancelled($run)` — terminal transitions.

## The dashboard

The companion [`timmcleod/agent-workflows-ui`](https://github.com/timmcleod/agent-workflows-ui) package renders each run as a live flowchart: completed steps green, the taken branch highlighted, failed attempts and retries in the audit trail, approval forms generated from `awaitHuman()` schemas, and round-by-round progress on `debate()` steps.

```bash
composer require timmcleod/agent-workflows-ui
```
