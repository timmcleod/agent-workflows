# Operations

What to know once workflows run in production:

- **Isolate the queue.** Point `agent-workflows.queue.connection`/`queue.queue` at a dedicated queue so long agent turns don't starve your app's other jobs, and give it its own worker process.
- **Give workers room.** An agent step is one full agentic turn — several LLM calls when tools are involved. Run its workers with a `--timeout` (and matching `retry_after`) sized to your slowest step, not one HTTP call. This matters: a `retry_after` shorter than a step makes the queue redeliver jobs whose first attempt is still executing. The engine ignores such redeliveries while the attempt looks live, but past the sweep's `stale_after` cutoff they fail the run and the original attempt's result is discarded.
- **Schedule the sweeper.** Workers die ungracefully (OOM, SIGKILL, deploys); the sweeper recovers runs stranded that way, re-dispatching from the checkpoint (or marking them failed, per `sweep.action`):

  ```php
  Schedule::command('agent-workflows:sweep')->everyFiveMinutes();
  ```

  Set `sweep.stale_after` comfortably above your longest step — including parallel fan-outs — so genuinely busy runs are never swept. Re-dispatch is safe: duplicate deliveries are rejected by the engine's step claims.
- **Execution semantics.** Step bodies are at-least-once (a crash after a side effect but before the checkpoint re-runs the body on recovery); checkpoints and cursor advances are exactly-once. Keep step side effects idempotent, or isolate them in their own step.

## Configuration

`config/agent-workflows.php`:

| Key                                                  | What it does                                                                 |
| ---------------------------------------------------- | ---------------------------------------------------------------------------- |
| `workflows`                                          | Your Workflow classes, registered at boot (workers included).               |
| `queue.connection` / `queue.queue`                   | Route step jobs onto their own connection/queue.                            |
| `tables.*`                                           | Rename the package's tables (runs, steps, interrupts).                      |
| `audit.step_output`                                  | `full` (default) or `minimal` — what step audit rows snapshot as output.    |
| `sweep.stale_after` / `sweep.action`                 | Staleness threshold (seconds) and recovery action for the sweeper.          |
| `definition_drift`                                   | `strict` (refuse to resume a changed definition) or `loose` (by step name) — see [definition drift](quick-start.md#deploys-and-definition-drift). |

