# Documentation

Start with the [package README](../README.md) for the pitch and the feature table, then:

- **[Quick start](quick-start.md)** — your first workflow end to end: agent, plain-PHP step, workflow class, start, resume, retry.
- **[The five patterns, made durable](five-patterns-made-durable.md)** — Laravel's official multi-agent patterns (`step()`, `when()`, `parallel()`, `evaluate()`) rewritten with checkpoints, retry, and resume.
- **[Agent debate](agent-debate.md)** — `debate()`: judge-ruled rounds, costs, retry semantics, and the transcript.
- **[Human-in-the-loop](human-in-the-loop.md)** — `awaitHuman()`, `awaitEvent()`, SLAs and timeouts, the SDK tool-approval bridge, and payload security.
- **[Agent steps](agent-steps.md)** — prompts defined at the step, tool loops inside one step, checkpointed output.
- **[Workflow state](typed-state.md)** — the state bag, `output()`, and typed per-workflow state classes.
- **[Runs, events, and observability](runs-and-observability.md)** — the Eloquent models, lifecycle events, and the dashboard.
- **[Testing](testing.md)** — `AgentWorkflow::fake()` and faked agents.
- **[Operations](operations.md)** — queue sizing, the sweeper, execution semantics, and every config key.
