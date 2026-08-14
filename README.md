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

> [!NOTE]
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
            ->when(fn (WorkflowState $state) => $state->output(RiskAnalysisAgent::class)?->structured('riskScore') >= 7,
                then: EscalationAgent::class,
                thenPrompt: $this->escalationPrompt(...))
            ->awaitHuman(reason: 'Final sign-off required',
                schema: ['approved' => 'required|boolean', 'notes' => 'nullable|string'])
            ->step(GenerateSummaryAgent::class, prompt: $this->summaryPrompt(...));
    }
}
```

```php
$run = ContractReview::start(['contract' => $text]);
// Each step runs as a queued job and is checkpointed as it completes. The run
// parks at the sign-off gate — through deploys, restarts, and weekends.

$run->resume(['approved' => true], by: $request->user());  // the manager answers on Tuesday
$run->retry();                                             // a step failed? re-run that step only —
                                                           // earlier steps keep their results and their token bill
```

The **[Quick Start](docs/quick-start.md)** builds a workflow like this end to end.

## The step types

Every step type in its simplest form — one acquisition review, end to end:

```php
// app/AgentWorkflows/AcquisitionReview.php
return $workflow
    // any invokable class is a step
    ->step(FetchFilings::class)

    // an agent: one checkpointed agentic turn
    ->step(SummarizeFilingsAgent::class)

    // fan out the analysis, merge when all finish
    ->parallel([
        FinancialAnalysisAgent::class,
        LegalAnalysisAgent::class,
    ])

    // argue the thesis; a judge rules each round
    ->debate(
        ['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class],
        judge: VerdictAgent::class,
        as: 'thesis')

    // no consensus? dig deeper, else skip ahead
    ->when(fn (WorkflowState $state) => ! $state->get('steps.thesis.satisfied'),
        then: DeepDiveAgent::class)

    // revise the memo until it is ready
    ->evaluate(DraftMemoAgent::class, as: 'memo',
        until: fn (WorkflowState $state) => $state->get('steps.memo.structured.score', 0) >= 8)

    // park for a person — hours or weeks
    ->awaitHuman(reason: 'Partner sign-off required')

    // park until the wire lands
    ->awaitEvent('funds.cleared')

    // close it out
    ->step(RecordInvestment::class);
```

Each one is documented in [Defining Workflows](docs/defining-workflows.md), [Human in the Loop](docs/human-in-the-loop.md), and [Agent Debates](docs/agent-debate.md).

## The dashboard (optional)

The core package has no UI and needs none — runs, steps, and interrupts are Eloquent models, and lifecycle events cover the rest, so your own controllers, dashboards, and listeners are the interface. If you want a ready-made view on top of that, the **optional** companion [`timmcleod/agent-workflows-ui`](https://github.com/timmcleod/agent-workflows-ui) package renders each run as a live, read-only flowchart: completed steps green, the taken branch highlighted, failed attempts and retries in the audit trail, and what each parked run is waiting for, straight from each gate's schema. The dashboard observes; approving, retrying, and cancelling stay in your application through the models above:

![The dashboard: a completed run rendered as a flowchart, the taken branch highlighted and the untaken branch dimmed, with the step-attempt audit trail alongside](https://raw.githubusercontent.com/timmcleod/agent-workflows-ui/main/art/dashboard.png)

```bash
composer require timmcleod/agent-workflows-ui
```

## Installation

Requires PHP 8.3+, Laravel 12 or 13, and `laravel/ai` ^0.10.3.

```bash
composer require timmcleod/agent-workflows

php artisan vendor:publish --tag=agent-workflows-config
php artisan migrate
```

Want to see it run first? The **[demo app](https://github.com/timmcleod/agent-workflows-demo)** is a complete contract-review pipeline — agents, a simulated outage for the retry demo, a risk-based branch, human sign-off, and an agent debate — driven from artisan commands.

## Features at a glance

| Feature | In one line | Details |
| --- | --- | --- |
| `step()` | Chain agents and plain PHP classes; every arrow in the chain is a checkpoint. | [Defining Workflows](docs/defining-workflows.md#steps) |
| `when()` | Branch on checkpointed state; the decision is recorded for audit. | [Defining Workflows](docs/defining-workflows.md#conditions) |
| `parallel()` | Durable fan-out as a queued `Bus::batch`, merged when all branches finish. | [Defining Workflows](docs/defining-workflows.md#parallel-steps) |
| `evaluate()` | Loop a step until a predicate passes, checkpointing every iteration, capped. | [Defining Workflows](docs/defining-workflows.md#loops) |
| `debate()` | Agents argue in rounds; a judge rules on the transcript after each. | [Agent Debates](docs/agent-debate.md) |
| `awaitHuman()` | Park for sign-off — hours or weeks — with validation schemas and SLA timeouts. | [Human in the Loop](docs/human-in-the-loop.md) |
| `awaitEvent()` | Park until another system delivers a named event (webhooks, payments). | [Human in the Loop](docs/human-in-the-loop.md) |
| Retry from checkpoint | `$run->retry()` re-runs only the failed step; earlier tokens stay paid. | [Runs & Observability](docs/runs-and-observability.md#managing-runs) |
| Tool-approval bridge | SDK tool approvals park the run; `resume()` replays the decisions. | [Human in the Loop](docs/human-in-the-loop.md#tool-approvals) |
| Agent steps | Prompts live on the step, not the agent; a step is one full agentic turn. | [Agent Steps](docs/agent-steps.md) |
| Workflow state | A checkpointed bag with `output()` addressing and typed per-workflow classes. | [Workflow State](docs/workflow-state.md) |
| Audit trail & events | Every attempt an Eloquent row (timings, tokens, errors); lifecycle events for everything. | [Runs & Observability](docs/runs-and-observability.md) |
| Per-call audit | Each attempt records every provider call inside it: model, tokens, tool calls, invocation id. | [Runs & Observability](docs/runs-and-observability.md#per-call-audit) |
| Testing fakes | `AgentWorkflow::fake()` assertions over really-executing workflows. | [Testing](docs/testing.md) |
| Definition drift | Deploy-changed workflows refuse to resume in-flight runs by default. | [Defining Workflows](docs/defining-workflows.md#definition-drift) |
| Operations | A sweeper for dead workers, queue-sizing rules, at-least-once semantics. | [Operations](docs/operations.md) |

## Documentation

Full documentation lives in the **[documentation index](docs/README.md)** — from the [Quick Start](docs/quick-start.md) through defining workflows, state, human gates, debates, testing, and operations.

## What this package is not

- **Not an arbitrary graph engine.** Sequential + conditional + parallel + loop + interrupt covers the overwhelming majority of production workflows. No cycles-with-reducers, no time-travel debugging.
- **Not a free-form group-chat framework.** Structured round-robin debate with a judge is packaged ([`debate()`](docs/agent-debate.md)); router-selected speakers and open-ended agent chatter deliberately stay build-your-own. The SDK's orchestrator-workers (sub-agents as tools) cover the rest.
- **Not a fork or patch of `laravel/ai`.** It composes the SDK's public API only, behind a single adapter seam.

## License

MIT
