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

`start()` accepts a registered string name or a `Workflow` class name (type-safe, refactor-friendly); both reach the same definition. Registration itself happens at boot from the config `workflows` array — `AgentWorkflow::register()` exists for runtime registration (tests, packages), and `AgentWorkflow::fake()` swaps in the recording manager for [tests](testing.md).

## Acting on a run

Everything on the run model shapes *what happens next for that one execution*:

| Method | What it does | When to use it |
| --- | --- | --- |
| `$run->retry()` | Re-dispatches a **failed** run from its failed step. Earlier steps keep their committed results. | After you've fixed whatever failed (provider outage, bad config, a bug). Throws unless the run's status is `failed`. |
| `$run->resume($response, by:)` | Wakes a run parked by `awaitHuman()` (validates against the schema, merges into state) or by a tool-approval pause (replays the decisions map into the agent). | When the human answers. `by:` records who, on the interrupt. |
| `$run->deliverEvent($event, $payload)` | Wakes a run parked by `awaitEvent()`; the payload merges into state. | From the webhook/listener where the awaited thing happens. |
| `$run->cancel()` | Ends the run as `cancelled`, resolving any open interrupt. A step already executing finishes but cannot advance a cancelled run — its result is discarded at the boundary. | Abandoning a run from any non-terminal state (including `failed`). |
| `$run->workflowState()` | The current checkpoint as a `WorkflowState` bag. | Reading run data in UIs or listeners (`$run->state` gives the raw array). |

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
