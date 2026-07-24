# Agent Workflows for Laravel

**Durable, resumable, human-interruptible agent workflows on top of the [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk).**

The official Laravel guidance shows how to compose the five multi-agent patterns (prompt chaining, routing, parallelization, orchestrator-workers, evaluator-optimizer) with framework primitives — `Pipeline`, `Concurrency::run()`, plain loops. All of it is in-process and ephemeral: a failure at step 4 reruns steps 1–3, nothing survives a deploy, and there is no way to pause for a human and continue tomorrow.

This package makes those same patterns **crash-safe** on the substrate Laravel already ships: queues, batches, retries, and Horizon.

- **Checkpointed** — workflow state is persisted after every step. A failed step retries *from that step*, not from the beginning.
- **Resumable** — runs survive crashes, deploys, and queue restarts.
- **Interruptible** — `awaitHuman()` parks a run for hours or days; `resume()` validates the human's input and continues.
- **Observable** — every step is a queued job (visible in Horizon), every run and step is a queryable Eloquent record, and lifecycle events fire throughout.

```php
AgentWorkflow::define('contract-review')
    ->start(ExtractClausesAgent::class)
    ->then(RiskAnalysisAgent::class)
    ->when(fn (WorkflowState $s) => $s->get('riskScore') > 7,
        then: EscalationAgent::class,
        else: AutoApproveAgent::class)
    ->awaitHuman(reason: 'Final sign-off required')
    ->then(GenerateSummaryAgent::class);
```

```php
$run = AgentWorkflow::start('contract-review', input: ['document_id' => $doc->id]);

// ... later — possibly days later, possibly after a deploy ...
$run->resume(HumanDecision::approve(by: $user));
```

> **Status: pre-release.** The core engine is under active development. APIs will change.

## What this package is not

- **Not an arbitrary graph engine.** Sequential + conditional + parallel + loop + interrupt covers the overwhelming majority of production workflows. No cycles-with-reducers, no time-travel debugging.
- **Not a group-chat / free-form agent-debate framework.** The SDK's orchestrator-workers (sub-agents as tools) plus handoffs cover the useful cases.
- **Not a fork or patch of `laravel/ai`.** It composes the SDK's public API only.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `laravel/ai` ^0.10

## License

MIT
