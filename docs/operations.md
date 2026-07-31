# Operations

- [Introduction](#introduction)
- [Queue Configuration](#queue-configuration)
- [The Sweeper](#the-sweeper)
- [Execution Semantics](#execution-semantics)
- [Configuration Reference](#configuration-reference)

## Introduction

This page covers what to know once workflows run in production: queue sizing, crash recovery, and the package's execution guarantees.

## Queue Configuration

You should point `agent-workflows.queue.connection` and `queue.queue` at a dedicated queue with its own worker process, so long agent turns do not starve your application's other jobs.

An agent step is one full agentic turn — several LLM calls when tools are involved. Run its workers with a `--timeout` (and a matching `retry_after`) sized to your slowest step, not to a single HTTP call.

> [!WARNING]
> A `retry_after` shorter than a step makes the queue redeliver jobs whose first attempt is still executing. The engine ignores such redeliveries while the attempt looks live, but past the sweeper's `stale_after` cutoff they fail the run and the original attempt's result is discarded.

## The Sweeper

Workers die ungracefully — OOM, SIGKILL, deploys. The sweeper recovers runs stranded that way, re-dispatching them from the checkpoint (or marking them failed, per `sweep.action`). Schedule it:

```php
Schedule::command('agent-workflows:sweep')->everyFiveMinutes();
```

Re-dispatch is safe: duplicate deliveries are rejected by the engine's step claims.

> [!WARNING]
> Set `sweep.stale_after` comfortably above your longest step — including parallel fan-outs and debate rounds — so genuinely busy runs are never swept.

## Execution Semantics

Step bodies are **at-least-once**: a crash after a side effect but before the checkpoint re-runs the body on recovery. Checkpoints and cursor advances are **exactly-once**. You should keep step side effects idempotent, or isolate them in their own step.

## Configuration Reference

The package's configuration lives in `config/agent-workflows.php`:

| Key | What it does |
| --- | --- |
| `workflows` | Your `Workflow` classes, registered at boot (workers included). |
| `queue.connection` / `queue.queue` | Route step jobs onto their own connection and queue. |
| `tables.*` | Rename the package's tables (runs, steps, interrupts). |
| `audit.step_output` | `full` (default) or `minimal` — what step audit rows snapshot as output. |
| `sweep.stale_after` / `sweep.action` | Staleness threshold (seconds) and recovery action for the sweeper. |
| `definition_drift` | `strict` (refuse to resume a changed definition) or `loose` (resume best-effort by step name) — see [definition drift](quick-start.md#definition-drift). |
