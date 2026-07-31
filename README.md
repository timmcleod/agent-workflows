# Agent Workflows for Laravel

[![tests](https://github.com/timmcleod/agent-workflows/actions/workflows/tests.yml/badge.svg)](https://github.com/timmcleod/agent-workflows/actions/workflows/tests.yml)
[![sdk-canary](https://github.com/timmcleod/agent-workflows/actions/workflows/sdk-canary.yml/badge.svg)](https://github.com/timmcleod/agent-workflows/actions/workflows/sdk-canary.yml)
[![Latest Version](https://img.shields.io/packagist/v/timmcleod/agent-workflows)](https://packagist.org/packages/timmcleod/agent-workflows)

**The [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk) is how your app talks to an AI. This package is how your app runs a *process* that involves AI: several steps, decisions, waiting on people, and picking up where it left off.**

Picture a real feature: reviewing a contract. Extract the clauses, score the risk, escalate if the risk is high, **wait for a manager to sign off**, then write the summary. With the SDK alone you hit a wall: PHP cannot wait until Tuesday for the manager to click approve — the request ends, everything the code knew is gone, and a failure at the last step re-runs (and re-bills) every step before it. Teams build the same pile of glue around that wall every time: a `pending_reviews` table, status columns, jobs, a resume endpoint, retry logic. This package is that pile, done once, properly, on the substrate Laravel already ships: queues, batches, and retries.

- **A process that can wait.** `awaitHuman()` parks a run in the database for hours or weeks; `resume()` validates the human's answer and continues. `awaitEvent()` does the same for webhooks and other systems.
- **Retry the step that broke, not the whole thing.** Every step's result is committed before the next step runs. If step 5 fails, `$run->retry()` re-runs step 5. Steps 1 to 4 keep their results and their token bill stays paid.
- **Memory between steps.** A state bag is saved after every step, so step 5 can read what step 2 produced, even days later on a different server.
- **A paper trail.** Every run and every attempt of every step is a queryable Eloquent record: status, timings, token counts, errors, who approved what and when.

Already know the multi-agent space? This package deliberately adopts the vocabulary of the five patterns from [Laravel's official multi-agent guidance](https://laravel.com/blog/building-multi-agent-workflows-with-the-laravel-ai-sdk) and makes each one crash-safe: see [The five patterns, made durable](docs/five-patterns-made-durable.md).

> **Status: pre-release.** The core engine (sequential, conditional, parallel, evaluator, and debate steps; checkpoint/retry; interrupts; events; testing fakes) is implemented and tested. APIs may change before 1.0.

## What it looks like

```php
// app/AgentWorkflows/ContractReview.php — prompt methods omitted
class ContractReview extends Workflow
{
    public function build(WorkflowDefinition $workflow): WorkflowDefinition
    {
        return $workflow
            ->step(ExtractClausesAgent::class, prompt: $this->extractPrompt(...))
            ->step(RiskAnalysisAgent::class, prompt: $this->riskPrompt(...))
            ->when(fn (WorkflowState $s) => $s->output(RiskAnalysisAgent::class)?->structured('riskScore') >= 7,
                then: EscalationAgent::class,
                thenPrompt: $this->escalationPrompt(...))
            ->awaitHuman(reason: 'Final sign-off required',
                schema: ['approved' => 'required|boolean', 'notes' => 'nullable|string'],
                timeout: CarbonInterval::days(3),
                timeoutResponse: ['approved' => false, 'notes' => 'Auto-rejected: sign-off timed out.'])
            ->step(GenerateSummaryAgent::class, prompt: $this->summaryPrompt(...));
    }
}
```

```php
$run = AgentWorkflow::start(ContractReview::class, input: ['contract' => $text]);
// Each step runs as a queued job and is checkpointed as it completes. The run
// parks at the sign-off gate — through deploys, restarts, and weekends.

$run->resume(['approved' => true], by: $request->user());  // the manager answers on Tuesday
$run->retry();                                             // a step failed? re-run that step only —
                                                           // earlier steps keep their results and their token bill
```

The **[quick start](docs/quick-start.md)** builds a workflow like this end to end.

## The dashboard

The companion [`timmcleod/agent-workflows-ui`](https://github.com/timmcleod/agent-workflows-ui) package renders each run as a live flowchart — completed steps green, the taken branch highlighted, failed attempts and retries in the audit trail, approval forms generated from each gate's schema:

![The dashboard: a completed run rendered as a flowchart, the taken branch highlighted and the untaken branch dimmed, with the step-attempt audit trail alongside](https://raw.githubusercontent.com/timmcleod/agent-workflows-ui/main/art/dashboard.png)

```bash
composer require timmcleod/agent-workflows-ui
```

## Installation

Requires PHP 8.3+, Laravel 12 or 13, and `laravel/ai` ^0.10.

```bash
composer require timmcleod/agent-workflows

php artisan vendor:publish --tag=agent-workflows-config
php artisan migrate
```

Want to see it run first? The **[demo app](https://github.com/timmcleod/agent-workflows-demo)** is a complete contract-review pipeline — agents, a simulated outage for the retry demo, a risk-based branch, human sign-off, and an agent debate — driven from artisan commands.

## Features at a glance

| Feature | In one line | Details |
| --- | --- | --- |
| `step()` | Chain agents and plain PHP classes; every arrow in the chain is a checkpoint. | [Patterns](docs/five-patterns-made-durable.md) |
| `when()` | Branch on checkpointed state; the decision is recorded for audit. | [Patterns](docs/five-patterns-made-durable.md) |
| `parallel()` | Durable fan-out as a queued `Bus::batch`, merged when all branches finish. | [Patterns](docs/five-patterns-made-durable.md) |
| `evaluate()` | Loop a step until a predicate passes, checkpointing every iteration, capped. | [Patterns](docs/five-patterns-made-durable.md) |
| `debate()` | Agents argue in rounds; a judge rules on the transcript after each. | [Agent debate](docs/agent-debate.md) |
| `awaitHuman()` | Park for sign-off — hours or weeks — with validation schemas and SLA timeouts. | [Human-in-the-loop](docs/human-in-the-loop.md) |
| `awaitEvent()` | Park until another system delivers a named event (webhooks, payments). | [Human-in-the-loop](docs/human-in-the-loop.md) |
| Retry from checkpoint | `$run->retry()` re-runs only the failed step; earlier tokens stay paid. | [Quick start](docs/quick-start.md#6-when-something-breaks) |
| Tool-approval bridge | SDK tool approvals park the run; `resume()` replays the decisions. | [Human-in-the-loop](docs/human-in-the-loop.md#sdk-tool-approvals-become-workflow-interrupts) |
| Agent steps | Prompts live on the step, not the agent; a step is one full agentic turn. | [Agent steps](docs/agent-steps.md) |
| Workflow state | A checkpointed bag with `output()` addressing and typed per-workflow classes. | [Workflow state](docs/typed-state.md) |
| Audit trail & events | Every attempt an Eloquent row (timings, tokens, errors); lifecycle events for everything. | [Observability](docs/runs-and-observability.md) |
| Testing fakes | `AgentWorkflow::fake()` assertions over really-executing workflows. | [Testing](docs/testing.md) |
| Definition drift | Deploy-changed workflows refuse to resume in-flight runs by default. | [Quick start](docs/quick-start.md#deploys-and-definition-drift) |
| Operations | A sweeper for dead workers, queue-sizing rules, at-least-once semantics. | [Operations](docs/operations.md) |

## Documentation

- **[Quick start](docs/quick-start.md)** — your first workflow end to end: agent, plain-PHP step, workflow class, start, resume, retry.
- **[The five patterns, made durable](docs/five-patterns-made-durable.md)** — Laravel's official multi-agent patterns rewritten with checkpoints, retry, and resume.
- **[Agent debate](docs/agent-debate.md)** — `debate()`: judge-ruled rounds, costs, retry semantics, and the transcript.
- **[Human-in-the-loop](docs/human-in-the-loop.md)** — `awaitHuman()`, `awaitEvent()`, timeouts, the tool-approval bridge, and payload security.
- **[Agent steps](docs/agent-steps.md)** — prompts defined at the step, tool loops inside one step, checkpointed output.
- **[Workflow state](docs/typed-state.md)** — the state bag, `output()`, and typed state classes.
- **[Runs, events, and observability](docs/runs-and-observability.md)** — the Eloquent models, lifecycle events, and the dashboard.
- **[Testing](docs/testing.md)** — `AgentWorkflow::fake()` and faked agents.
- **[Operations](docs/operations.md)** — queue sizing, the sweeper, execution semantics, and every config key.

## What this package is not

- **Not an arbitrary graph engine.** Sequential + conditional + parallel + loop + interrupt covers the overwhelming majority of production workflows. No cycles-with-reducers, no time-travel debugging.
- **Not a free-form group-chat framework.** Structured round-robin debate with a judge is packaged ([`debate()`](docs/agent-debate.md)); router-selected speakers and open-ended agent chatter deliberately stay build-your-own. The SDK's orchestrator-workers (sub-agents as tools) cover the rest.
- **Not a fork or patch of `laravel/ai`.** It composes the SDK's public API only, behind a single adapter seam.

## License

MIT
