# Operations

- [Introduction](#introduction)
- [Queue Configuration](#queue-configuration)
- [The Sweeper](#the-sweeper)
- [Execution Semantics](#execution-semantics)
- [Configuration Reference](#configuration-reference)
- [Upgrading](#upgrading)

## Introduction

This page covers what to know once workflows run in production: queue sizing, crash recovery, and the package's execution guarantees.

## Queue Configuration

You should point `agent-workflows.queue.connection` and `queue.queue` at a dedicated queue with its own worker process, so long agent turns do not starve your application's other jobs.

You should size worker `--timeout` and the connection's `retry_after` to your **slowest step**, not to a single HTTP call:

- An [agent step is one full agentic turn](agent-steps.md#tools): several LLM calls when tools are involved.
- A [debate round](agent-debate.md) is the slowest step body the package ships: `debaters + 1` sequential agent calls, each slower than the last as the transcript grows. Budget roughly `(debaters + 1) × your slowest single agent call`, and remember later rounds run longer than round one.

> [!WARNING]
> A `retry_after` shorter than a step makes the queue redeliver jobs whose first attempt is still executing. The engine ignores such redeliveries while the attempt looks live, but past the sweeper's `stale_after` cutoff they fail the run and the original attempt's result is discarded. If your steps run long, raise `retry_after` and `sweep.stale_after` together.

## The Sweeper

Sometimes workers die ungracefully: OOM, SIGKILL, deploys. The sweeper recovers runs stranded this way, re-dispatching them from the checkpoint or marking them failed, per `sweep.action`. You should schedule it:

```php
Schedule::command('agent-workflows:sweep')->everyFiveMinutes();
```

Re-dispatch is safe: duplicate deliveries are rejected by the engine's step claims.

> [!WARNING]
> You should set `sweep.stale_after` comfortably above your longest step, including parallel fan-outs and debate rounds, so genuinely busy runs are never swept.

## Execution Semantics

Step bodies are **at-least-once**: a crash after a side effect but before the checkpoint re-runs the body on recovery. Checkpoints and cursor advances are **exactly-once**. You should keep step side effects idempotent, or isolate them in their own step.

## Configuration Reference

The package's configuration lives in `config/agent-workflows.php`:

| Key | What it does |
| --- | --- |
| `workflows` | Your `Workflow` classes, registered at boot (workers included). |
| `queue.connection` / `queue.queue` | Route step jobs onto their own connection and queue. |
| `tables.*` | Rename the package's tables (runs, steps, interrupts). |
| `audit.step_output` | `full` (default) or `minimal`: what step audit rows snapshot as output. |
| `audit.step_calls` | `full` (default) or `minimal`: whether the [per-call audit](runs-and-observability.md#per-call-audit) records tool arguments and results, or only ids and names. |
| `sweep.stale_after` / `sweep.action` | Staleness threshold (seconds) and recovery action for the sweeper. |
| `parallel.sync_driver` | Concurrency driver for parallel branches when workflow jobs run on the **sync queue** (test suites): `sync` (default) keeps branches in-process; real queue connections are unaffected. |
| `definition_drift` | `strict` (refuse to resume a changed definition) or `loose` (resume best-effort by step name); see [definition drift](defining-workflows.md#definition-drift). |

## Upgrading

The package's migrations are additive. After upgrading, you should run:

```bash
php artisan migrate
```

Existing behavior keeps working against a not-yet-migrated runs table (the engine skips the new columns until they exist), so a deploy window between code and migration does not wedge in-flight runs. The new features themselves (`key:`, `group:`, `meta`) require the migration.
