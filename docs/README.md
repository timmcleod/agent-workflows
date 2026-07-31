# Documentation

Start with the [package README](../README.md) for the pitch and the feature table.

## Getting Started

- **[Quick Start](quick-start.md)** — installation, then your first workflow end to end: agent, callback step, workflow class, start, resume, retry.

## Core Concepts

- **[Defining Workflows](defining-workflows.md)** — workflow classes and registration, steps and aliases, conditions, parallel fan-outs, and loops.
- **[Agent Steps](agent-steps.md)** — prompts defined at the step, tool loops inside one step, checkpointed output.
- **[Workflow State](workflow-state.md)** — the state bag, the `output` method, and typed per-workflow state classes.

## Waiting

- **[Human in the Loop](human-in-the-loop.md)** — `awaitHuman`, `awaitEvent`, SLAs and timeouts, the SDK tool-approval bridge, and payload security.

## Multi-Agent

- **[Agent Debates](agent-debate.md)** — the `debate` method: judge-ruled rounds, costs, retry semantics, and the transcript.

## Digging Deeper

- **[Runs & Observability](runs-and-observability.md)** — the Eloquent models, lifecycle events, and the dashboard.
- **[Testing](testing.md)** — `AgentWorkflow::fake` and faked agents.
- **[Operations](operations.md)** — queue sizing, the sweeper, execution semantics, and every config key.

## Articles

Longer reads that argue a design rather than document an API:

- **[The five patterns, made durable](five-patterns-made-durable.md)** — Laravel's official multi-agent patterns (`step`, `when`, `parallel`, `evaluate`) rewritten with checkpoints, retry, and resume.
