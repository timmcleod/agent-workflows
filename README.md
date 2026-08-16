<p align="center">
  <a href="https://timmcleod.github.io/agent-workflows/">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://timmcleod.github.io/agent-workflows/lockup-dark.svg">
      <img src="https://timmcleod.github.io/agent-workflows/lockup.svg" alt="Agent Workflows for Laravel" width="540">
    </picture>
  </a>
</p>

<p align="center">
  <a href="https://github.com/timmcleod/agent-workflows/actions/workflows/tests.yml"><img src="https://github.com/timmcleod/agent-workflows/actions/workflows/tests.yml/badge.svg" alt="tests"></a>
  <a href="https://github.com/timmcleod/agent-workflows/actions/workflows/sdk-canary.yml"><img src="https://github.com/timmcleod/agent-workflows/actions/workflows/sdk-canary.yml/badge.svg" alt="sdk-canary"></a>
  <a href="https://packagist.org/packages/timmcleod/agent-workflows"><img src="https://img.shields.io/packagist/v/timmcleod/agent-workflows" alt="Latest Version"></a>
</p>

**The [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk) is how your app talks to an AI. This package is how your app runs a *process* that involves AI: several steps, decisions, waiting on people, and picking up where it left off.**

Picture a real feature: reviewing a contract. Extract the clauses, score the risk, escalate if the risk is high, **wait for a manager to sign off**, then write the summary. PHP cannot wait until Tuesday for the manager to click approve, so teams build the same pile of glue every time: a `pending_reviews` table, status columns, jobs, a resume endpoint, retry logic. This package is that pile, done once, on the substrate Laravel already ships: queues, batches, and retries.

- **A process that can wait.** `awaitHuman()` parks a run in the database for hours or weeks; `resume()` validates the human's answer and continues. `awaitEvent()` does the same for webhooks and other systems.
- **Retry the step that broke, not the whole thing.** Every step's result is committed before the next runs; earlier steps keep their results and their token bill stays paid.
- **Memory between steps.** A state bag is saved after every step, so step 5 can read what step 2 produced, even days later on a different server.
- **A paper trail.** Every run and every attempt of every step is a queryable Eloquent record: status, timings, token counts, errors, who approved what and when.

Already know the multi-agent space? This package keeps the vocabulary of the five agent patterns from the [Laravel blog's multi-agent walkthrough](https://laravel.com/blog/building-multi-agent-workflows-with-the-laravel-ai-sdk) and makes each one crash-safe: see [Five patterns, made durable](https://timmcleod.github.io/agent-workflows/five-patterns-made-durable).

> [!NOTE]
> **Status: pre-release.** The core engine (sequential, conditional, parallel, evaluator, and debate steps; checkpoint/retry; interrupts; events; testing fakes) is implemented and tested. APIs may change before 1.0.

## What it looks like

```php
// app/AgentWorkflows/ContractReview.php
class ContractReview extends Workflow
{
    public function build(WorkflowDefinition $workflow): WorkflowDefinition
    {
        return $workflow
            ->step(
                ExtractClausesAgent::class, 
                'Extract the key clauses: {{ contract }}'
            )
            ->step(
                RiskAnalysisAgent::class, 
                'Assess the risk of: {{ output:ExtractClausesAgent }}'
            )
            ->when(
                fn (WorkflowState $state) => $state->output(RiskAnalysisAgent::class)?->structured('riskScore') >= 7,
                then: EscalationAgent::class,
                thenPrompt: 'Draft an escalation memo covering: {{ output:RiskAnalysisAgent }}'
            )
            ->awaitHuman(
                reason: 'Final sign-off required',
                schema: ['approved' => 'required|boolean', 'notes' => 'nullable|string']
            )
            ->step(
                GenerateSummaryAgent::class, 
                'Summarize this review for the record: {{ output:RiskAnalysisAgent }}'
            );
    }
}
```

```php
$run = ContractReview::start(['contract' => $text]);
// Each step runs as a queued job and is checkpointed as it completes. The run
// parks at the sign-off gate, through deploys, restarts, and weekends.

$run->resume(['approved' => true], by: $request->user());  // the manager answers on Tuesday
$run->retry();                                             // a step failed? re-run that step only;
                                                           // earlier steps keep their results and their token bill
```

The **[Quick Start](https://timmcleod.github.io/agent-workflows/quick-start)** builds a workflow like this end to end.

## The step types

Every step type in its simplest form, one acquisition review from end to end:

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
        [LegalAnalysisAgent::class, 'Review for legal exposure: {{ output:SummarizeFilingsAgent }}'],
    ])

    // argue the thesis; a judge rules each round
    ->debate(
        ['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class],
        judge: VerdictAgent::class,
        as: 'thesis'
    )

    // no consensus? dig deeper, else skip ahead
    ->when(
        fn (WorkflowState $state) => ! $state->get('steps.thesis.satisfied'),
        then: DeepDiveAgent::class
    )

    // revise the memo until it is ready
    ->evaluate(
        DraftMemoAgent::class,
        as: 'memo',
        until: fn (WorkflowState $state) => $state->get('steps.memo.structured.score', 0) >= 8
    )

    // park for a person, for hours or weeks
    ->awaitHuman(reason: 'Partner sign-off required')

    // park until the wire lands
    ->awaitEvent('funds.cleared')

    // close it out
    ->step(RecordInvestment::class);
```

Each one is documented in [Defining Workflows](https://timmcleod.github.io/agent-workflows/defining-workflows), [Human in the Loop](https://timmcleod.github.io/agent-workflows/human-in-the-loop), and [Agent Debates](https://timmcleod.github.io/agent-workflows/agent-debate).

## Installation

Requires PHP 8.3+, Laravel 12 or 13, and `laravel/ai` ^0.10.3:

```bash
composer require timmcleod/agent-workflows
```

The **[Quick Start](https://timmcleod.github.io/agent-workflows/quick-start)** takes it from there. Want to see it run first? The **[demo app](https://github.com/timmcleod/agent-workflows-demo)** is a complete contract-review pipeline (agents, a simulated outage for the retry demo, a risk-based branch, human sign-off, and an agent debate) driven from artisan commands.

## Features at a glance

| Feature | In one line | Details |
| --- | --- | --- |
| `step()` | Chain agents and plain PHP classes; every arrow in the chain is a checkpoint. | [Defining Workflows](https://timmcleod.github.io/agent-workflows/defining-workflows#steps) |
| `when()` | Branch on checkpointed state; the decision is recorded for audit. | [Defining Workflows](https://timmcleod.github.io/agent-workflows/defining-workflows#conditions) |
| `parallel()` | Durable fan-out as a queued `Bus::batch`, merged when all branches finish; branches carry their own prompts. | [Defining Workflows](https://timmcleod.github.io/agent-workflows/defining-workflows#parallel-steps) |
| `evaluate()` | Loop a step until a predicate passes, checkpointing every iteration, capped. | [Defining Workflows](https://timmcleod.github.io/agent-workflows/defining-workflows#loops) |
| `debate()` | Agents argue in rounds; a judge rules on the transcript after each. | [Agent Debates](https://timmcleod.github.io/agent-workflows/agent-debate) |
| `awaitHuman()` | Park for sign-off, hours or weeks, with validation schemas and SLA timeouts. | [Human in the Loop](https://timmcleod.github.io/agent-workflows/human-in-the-loop#awaiting-human-input) |
| `awaitEvent()` | Park until another system delivers a named event (webhooks, payments). | [Human in the Loop](https://timmcleod.github.io/agent-workflows/human-in-the-loop#awaiting-application-events) |
| Retry from checkpoint | `$run->retry()` re-runs only the failed step; earlier tokens stay paid. | [Runs & Observability](https://timmcleod.github.io/agent-workflows/runs-and-observability#retry-semantics) |
| Tool-approval bridge | SDK tool approvals park the run; `resume()` replays the decisions. | [Human in the Loop](https://timmcleod.github.io/agent-workflows/human-in-the-loop#tool-approvals) |
| Agent steps | Prompts live on the step, not the agent; a step is one full agentic turn. | [Agent Steps](https://timmcleod.github.io/agent-workflows/agent-steps) |
| Workflow state | A checkpointed bag with `output()` addressing and typed per-workflow classes. | [Workflow State](https://timmcleod.github.io/agent-workflows/workflow-state) |
| Audit trail & events | Every attempt an Eloquent row (timings, tokens, errors); lifecycle events for everything. | [Runs & Observability](https://timmcleod.github.io/agent-workflows/runs-and-observability) |
| Per-call audit | Each attempt records every provider call inside it: model, tokens, tool calls, invocation id. | [Runs & Observability](https://timmcleod.github.io/agent-workflows/runs-and-observability#per-call-audit) |
| Testing fakes | `AgentWorkflow::fake()` assertions over really-executing workflows. | [Testing](https://timmcleod.github.io/agent-workflows/testing) |
| Definition drift | Deploy-changed workflows refuse to resume in-flight runs by default. | [Defining Workflows](https://timmcleod.github.io/agent-workflows/defining-workflows#definition-drift) |
| Operations | A sweeper for dead workers, queue-sizing rules, at-least-once semantics. | [Operations](https://timmcleod.github.io/agent-workflows/operations) |

## Documentation

Full documentation lives in the **[documentation index](https://timmcleod.github.io/agent-workflows/)**, from the [Quick Start](https://timmcleod.github.io/agent-workflows/quick-start) through defining workflows, state, human gates, debates, testing, and operations.

## What this package is not

- **Not an arbitrary graph engine.** Sequential + conditional + parallel + loop + interrupt covers the overwhelming majority of production workflows. No cycles-with-reducers, no time-travel debugging.
- **Not a free-form group-chat framework.** Structured round-robin debate with a judge is packaged ([`debate()`](https://timmcleod.github.io/agent-workflows/agent-debate)); router-selected speakers and open-ended agent chatter deliberately stay build-your-own. The SDK's orchestrator-workers (sub-agents as tools) cover the rest.
- **Not a fork or patch of `laravel/ai`.** It composes the SDK's public API only, behind a single adapter seam.

## License

MIT
