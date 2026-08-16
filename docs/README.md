# Documentation

## Start Here

- **[Quick Start](quick-start.md)**: installation, then your first workflow end to end (agent, callback step, workflow class, start, resume, retry).
- **[Five patterns, made durable](five-patterns-made-durable.md)**: the five multi-agent patterns from the Laravel blog mapped onto their durable counterparts, for readers who already know the space.
- **[How It Works](how-it-works.md)**: a visual tour of the engine, from the big picture down to the class map. Read it when you want to trust the machine.

## Building Workflows

- **[Defining Workflows](defining-workflows.md)**: workflow classes and registration, steps and aliases, conditions, parallel fan-outs, loops, and definition drift.
- **[Agent Steps](agent-steps.md)**: the prompt ladder (strings, templates, closures, conventional methods), tool loops, and approval pauses.
- **[Workflow State](workflow-state.md)**: the state bag, reading step output, and typed per-workflow state classes.
- **[Human in the Loop](human-in-the-loop.md)**: `awaitHuman`, `awaitEvent`, SLAs and timeouts, the SDK tool-approval bridge, and payload security.
- **[Agent Debates](agent-debate.md)**: judge-ruled rounds via the `debate` method, costs, retry semantics, and the transcript.

## Running in Production

- **[Runs & Observability](runs-and-observability.md)**: the Eloquent models, per-call audit, retry semantics, and lifecycle events.
- **[Testing](testing.md)**: `AgentWorkflow::fake`, faked agents, gates, and debates.
- **[Operations](operations.md)**: queue sizing, the sweeper, execution semantics, every config key, and upgrading.
