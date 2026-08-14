# Runs & Observability

- [Introduction](#introduction)
- [Inspecting Runs](#inspecting-runs)
  - [Per-Call Audit](#per-call-audit)
  - [Run Progress](#run-progress)
  - [Run Metadata](#run-metadata)
- [Singleton Keys](#singleton-keys)
- [Run Groups](#run-groups)
- [Managing Runs](#managing-runs)
- [Events](#events)
- [Dashboard](#dashboard)

## Introduction

Everything a run does is queryable three ways: the Eloquent models, the lifecycle events, and the dashboard.

## Inspecting Runs

Runs, steps, and interrupts are plain Eloquent models:

```php
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

$run = WorkflowRun::find($id);

$run->status;          // pending | running | awaiting_human | awaiting_event | failed | completed | cancelled
$run->current_step;    // the cursor
$run->state;           // the latest checkpoint (array)
$run->steps;           // audit log: every attempt of every step, with
                       // input-state hash, output-state snapshot, token usage,
                       // per-call detail, timings, and errors
```

### Per-Call Audit

An agent step is one full agentic turn, which may span several provider calls: the model answers, calls a tool, sees the result, answers again. The step row's `calls` column records each of those calls in order, so a slow or expensive step is not a black box between `started_at` and `finished_at`:

```php
$run->steps()->where('step_id', 'judge')->latest('id')->first()->calls;
// [
//   ['invocation_id' => '019...', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5',
//    'finish_reason' => 'tool_calls', 'usage' => ['prompt_tokens' => 2114, ...],
//    'tool_calls' => [['id' => 'toolu_1', 'name' => 'fetch_filings', 'arguments' => [...]]],
//    'tool_results' => [['id' => 'toolu_1', 'name' => 'fetch_filings', 'result' => [...]]]],
//   ['invocation_id' => '019...', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5',
//    'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 3480, ...]],
// ]
```

Each entry carries the SDK's invocation id (one id per prompt, so a [debate round's](agent-debate.md) debaters and judge stay distinguishable on one row), the provider and model that actually responded (under failover, not necessarily the ones requested), per-call token usage, and the finish reason. `provider` and `model` come from the SDK's response metadata; the step's summed usage stays in the `usage` column.

Tool arguments and results can be large, and they can carry sensitive input. The `audit.step_calls` config option trims them: `"full"` (default) records both; `"minimal"` keeps only tool ids and names. Steps that make no provider calls record `null`, as do rows written before the column's migration ran.

An attempt parked by a [tool-approval pause](human-in-the-loop.md#tool-approvals) records the usage and calls of the provider call that requested the approval, since that call completed and was billed even though the step did not finish. The resumed attempt is a separate row carrying its own calls, so cost accounting sums cleanly over attempts.

You may associate a run with a user — or any model — via the polymorphic `participant` argument:

```php
ContractReview::start(input: [...], participant: $user);
```

The static `start` method on the workflow class delegates to the `AgentWorkflow::start` facade method, which also accepts a registered string name; both reach the same definition. Registration itself happens at boot from the config `workflows` array — `AgentWorkflow::register` exists for runtime registration (tests, packages), and `AgentWorkflow::fake` swaps in the recording manager for [tests](testing.md).

### Run Progress

The `progress` method reports where a run is within its workflow, for live progress displays:

```php
$run->progress();
// ['step' => 'judge', 'label' => 'Fact-checking the report',
//  'index' => 4, 'total' => 5, 'status' => 'running']
```

Labels come from the optional `label` argument every step-declaring method accepts (see [defining workflows](defining-workflows.md#steps)); unlabeled steps get sensible defaults. Progress resolves against the definition's ordered top-level steps — a cursor inside a parallel branch or a condition branch reports the owning step, and loops do not inflate the total. The method never throws: when the definition has drifted past the cursor's step, or the workflow is not registered in the current process, it degrades to the raw step id with `index` and `total` zeroed.

### Run Metadata

The `meta` column is app-owned storage on the run — external references, audit tags, notification receipts. The engine never reads or writes it: checkpoints, retries, sweeps, and resumes leave it untouched. The `mergeMeta` method merges under a lock, so two writers do not clobber each other:

```php
$run->mergeMeta(['slack_ts' => $timestamp]);

$run->meta; // ['slack_ts' => '...']
```

## Singleton Keys

You may enforce "one active run per business entity" by passing a `key` when starting a run. Keys are scoped per workflow name:

```php
$run = TicketInvestigation::start($input, key: "ticket:{$ticket->id}");

$run->wasRecentlyCreated; // false when an existing active run was returned
```

When an active run (pending, running, or awaiting) already holds the key, `start` returns **that run** instead of creating a new one. The idempotent return is side-effect free — no `WorkflowStarted` event, no step job — and `wasRecentlyCreated` is the caller's signal. The guard is a unique database index, not a check-then-act query, so two concurrent starts yield exactly one new run.

A terminal transition (completed, failed, cancelled) frees the key, so history accumulates freely while at most one run per key is active. Retrying a failed run re-claims its key — and throws a descriptive exception when another run has claimed it since.

When `key` and `group` are combined and `start` returns an existing run, the run adopts the requested group only when it has none — an established group is never silently rewritten.

> [!WARNING]
> Singleton keys rely on NULLs not colliding in unique indexes, which holds on SQLite, MySQL, MariaDB, and Postgres. SQL Server treats NULLs as equal there and is not supported.

## Run Groups

You may start several runs into a named group and act once when the last one finishes. Groups are global, so runs of different workflows may share one:

```php
TicketInvestigation::start($input, group: "conversation:{$id}");
```

When a run in the group reaches a terminal status and no members remain active, the group **settles**: a `WorkflowGroupSettled` event delivers every terminal run not covered by an earlier settle:

```php
use TimMcLeod\AgentWorkflows\Events\WorkflowGroupSettled;

Event::listen(WorkflowGroupSettled::class, function (WorkflowGroupSettled $event) {
    // $event->groupKey, $event->runs (Collection<WorkflowRun>)
    SummarizeInvestigations::dispatch($event->groupKey, $event->runs);
});
```

Groups may settle more than once: runs that join the group later — or a settled failed run that is retried or cancelled — are delivered in the following settle.

The guarantee, precisely: each run outcome is **stamped** settled exactly once, atomically, so no two settles ever carry the same outcome and listeners need no locks or markers of their own. The event itself dispatches after the stamping transaction commits, with the same delivery guarantee as every other lifecycle event — and the [sweeper](operations.md) re-settles any group whose settle never ran (a worker died, or a lifecycle listener threw first). Queue your settle listeners if their work must survive listener failures.

## Managing Runs

Everything on the run model shapes *what happens next for that one execution*:

| Method | What it does | When to use it |
| --- | --- | --- |
| `$run->retry()` | Re-dispatches a **failed** run from its failed step. Earlier steps keep their committed results. | After you've fixed whatever failed (provider outage, bad config, a bug). Throws unless the run's status is `failed`. |
| `$run->resume($response, by:)` | Wakes a run parked by `awaitHuman` (validates against the schema, merges into state) or by a tool-approval pause (replays the decisions map into the agent). | When the human answers. `by:` records who, on the interrupt. |
| `$run->deliverEvent($event, $payload)` | Wakes a run parked by `awaitEvent`; the payload merges into state. | From the webhook or listener where the awaited thing happens. |
| `$run->cancel()` | Ends the run as `cancelled`, resolving any open interrupt. A step already executing finishes but cannot advance a cancelled run — its result is discarded at the boundary. | Abandoning a run from any non-terminal state (including `failed`). |
| `$run->workflowState()` | The current checkpoint as a `WorkflowState` bag. | Reading run data in UIs or listeners (`$run->state` gives the raw array). |

## Events

You may listen for lifecycle events anywhere you would listen for any Laravel event:

```php
use TimMcLeod\AgentWorkflows\Events\WorkflowFailed;

Event::listen(WorkflowFailed::class, function (WorkflowFailed $event) {
    Notification::send($ops, new WorkflowFailedNotification($event->run, $event->exception));
});
```

The full set lives under the `TimMcLeod\AgentWorkflows\Events` namespace:

| Event | Fires when |
| --- | --- |
| `WorkflowStarted($run)` | A run was created. |
| `StepCompleted($run, $step)` | A step completed — fires per step, including parallel branches, and carries the audit row (token usage included), which makes cost accounting a one-listener job. |
| `WorkflowInterrupted($run, $interrupt)` | The run parked: `awaitHuman`, `awaitEvent`, or an agent tool-approval pause. The interrupt carries the reason, response schema, and context. |
| `WorkflowResumed($run, $interrupt)` | A human resumed the run or the awaited event arrived. |
| `WorkflowCompleted($run)` | The run finished successfully. |
| `WorkflowFailed($run, $exception)` | The run failed. |
| `WorkflowCancelled($run)` | The run was cancelled. |
| `WorkflowGroupSettled($groupKey, $runs)` | A [run group](#run-groups) settled — carries the terminal runs delivered in this settle, exactly once each. |

## Dashboard

The models and events above are the package's complete interface — no UI is required. If you want one, the optional companion [`timmcleod/agent-workflows-ui`](https://github.com/timmcleod/agent-workflows-ui) package renders each run as a live, read-only flowchart: completed steps green, the taken branch highlighted, failed attempts and retries in the audit trail, the expected response fields from `awaitHuman` schemas shown read-only, and round-by-round progress on `debate` steps. The dashboard observes; resuming, delivering events, retrying, and cancelling stay in your application.

```bash
composer require timmcleod/agent-workflows-ui
```
