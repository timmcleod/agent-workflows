# Runs & Observability

- [Introduction](#introduction)
- [Inspecting Runs](#inspecting-runs)
  - [Participants](#participants)
  - [Per-Call Audit](#per-call-audit)
  - [Run Progress](#run-progress)
  - [Run Metadata](#run-metadata)
- [Singleton Keys](#singleton-keys)
- [Run Groups](#run-groups)
- [Managing Runs](#managing-runs)
  - [Retry Semantics](#retry-semantics)
- [Events](#events)

## Introduction

Everything a run does is queryable two ways: the Eloquent models and the lifecycle events.

## Inspecting Runs

Runs, steps, and interrupts are plain Eloquent models:

```php
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

$run = WorkflowRun::find($id);

$run->status;          // a RunStatus enum: Pending | Running | AwaitingHuman |
                       // AwaitingEvent | Failed | Completed | Cancelled
$run->current_step;    // the cursor
$run->state;           // the latest checkpoint (array)
$run->steps;           // audit log: every attempt of every step, with
                       // input-state hash, output-state snapshot, token usage,
                       // per-call detail, timings, and errors
```

### Participants

You may associate a run with a user, or any model, via the polymorphic `participant` argument to [`start`](defining-workflows.md#workflow-classes):

```php
ContractReview::start(input: [...], participant: $user);
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

Each entry carries the SDK's invocation id (one id per prompt, so a [debate round's](agent-debate.md) debaters and judge stay distinguishable on one row), the provider and model that actually responded (under failover, not necessarily the ones requested), per-call token usage, and the finish reason. `provider` and `model` come from the SDK's response metadata, and the step's summed usage stays in the `usage` column.

Tool arguments and results can be large, and they can carry sensitive input. The `audit.step_calls` config option trims them: `"full"` (default) records both, while `"minimal"` keeps only tool ids and names. Steps that make no provider calls record `null`, as do rows written before the column's migration ran.

An attempt parked by a [tool-approval pause](human-in-the-loop.md#tool-approvals) records the usage and calls of the provider call that requested the approval, since that call completed and was billed even though the step did not finish. The resumed attempt is a separate row carrying its own calls, so cost accounting sums cleanly over attempts.

### Run Progress

The `progress` method reports where a run is within its workflow, for live progress displays:

```php
$run->progress();
// ['step' => 'judge', 'label' => 'Fact-checking the report',
//  'index' => 4, 'total' => 5, 'status' => 'running']
```

Labels come from the optional `label` argument every step-declaring method accepts (see [defining workflows](defining-workflows.md#steps)). Progress resolves against the definition's ordered top-level steps: a cursor inside a parallel branch or a condition branch reports the owning step, and loops do not inflate the total. The method never throws: when the definition has drifted past the cursor's step, or the workflow is not registered in the current process, it degrades to the raw step id with `index` and `total` zeroed.

### Run Metadata

The `meta` column is app-owned storage on the run: external references, audit tags, notification receipts. The engine never reads or writes it: checkpoints, retries, sweeps, and resumes leave it untouched. The `mergeMeta` method merges under a lock, so two writers do not clobber each other:

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

When an active run (pending, running, or awaiting) already holds the key, `start` returns **that run** instead of creating a new one. The idempotent return is side-effect free (no `WorkflowStarted` event, no step job) and `wasRecentlyCreated` is the caller's signal. Two concurrent starts yield exactly one new run, guaranteed by a unique index.

A terminal transition (completed, failed, cancelled) frees the key, so history accumulates freely while at most one run per key is active. Retrying a failed run re-claims its key, and throws a descriptive exception when another run has claimed it since.

When `key` and `group` are combined and `start` returns an existing run, the run adopts the requested group only when it has none. An established group is never silently rewritten.

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

Groups may settle more than once: runs that join the group later, or a settled failed run that is retried or cancelled, are delivered in the following settle.

Each run outcome is delivered in exactly one settle, guaranteed atomically, so listeners need no locks or markers of their own. The event dispatches after commit, and the [sweeper](operations.md#the-sweeper) re-settles any group whose settle never ran. You should queue your settle listeners if their work must survive listener failures.

## Managing Runs

Everything on the run model shapes *what happens next for that one execution*:

| Method | What it does | When to use it |
| --- | --- | --- |
| `$run->retry()` | Re-dispatches a **failed** run from its failed step. Earlier steps keep their committed results. | After you've fixed whatever failed (provider outage, bad config, a bug). Throws unless the run's status is `failed`. |
| `$run->resume($response, by:)` | Wakes a run parked by `awaitHuman` (validates against the schema, merges into state) or by a tool-approval pause (replays the decisions map into the agent). | When the human answers. `by:` records who, on the interrupt. |
| `$run->deliverEvent($event, $payload)` | Wakes a run parked by `awaitEvent`; the payload merges into state. | From the webhook or listener where the awaited thing happens. |
| `$run->cancel()` | Ends the run as `cancelled`, resolving any open interrupt. | Abandoning a run from any non-terminal state (including `failed`). |
| `$run->workflowState()` | The current checkpoint as a `WorkflowState` bag. | Reading run data in UIs or listeners (`$run->state` gives the raw array). |

Cancelling never interrupts a step mid-execution: a step already running finishes its work, but a cancelled run cannot advance, so the step's result is discarded at the boundary and no further steps dispatch.

### Retry Semantics

`retry()` re-runs from the failed step. Everything committed before it stays committed, and its tokens stay paid. What "the failed step" means depends on the step type:

| Step type | Retry unit |
| --- | --- |
| Agent or callback step | The step. A [tool-using agent's whole turn](agent-steps.md#retries-and-side-effects) re-runs from its first call. |
| `parallel()` | The whole fan-out: every branch re-runs. |
| `evaluate()` | The failing iteration; committed iterations keep their checkpoints. |
| `debate()` | The failing round; [committed rounds stay committed](agent-debate.md#retries). |
| `awaitHuman()` with a timeout that failed the run | The gate re-parks and its deadline re-arms. |

Step bodies are at-least-once while checkpoints are exactly-once. See [execution semantics](operations.md#execution-semantics) for the operational contract.

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
| `StepCompleted($run, $step)` | A step completed. Fires per step, including parallel branches, and carries the audit row (token usage included), which makes cost accounting a one-listener job. |
| `WorkflowInterrupted($run, $interrupt)` | The run parked: `awaitHuman`, `awaitEvent`, or an agent tool-approval pause. The interrupt carries the reason, response schema, and context. |
| `WorkflowResumed($run, $interrupt)` | A human resumed the run or the awaited event arrived. |
| `WorkflowCompleted($run)` | The run finished successfully. |
| `WorkflowFailed($run, $exception)` | The run failed. |
| `WorkflowCancelled($run)` | The run was cancelled. |
| `WorkflowGroupSettled($groupKey, $runs)` | A [run group](#run-groups) settled. Carries the terminal runs delivered in this settle, exactly once each. |
