# The five patterns, made durable

Laravel's official guidance — [Building Multi-Agent Workflows with the Laravel AI SDK](https://laravel.com/blog/building-multi-agent-workflows-with-the-laravel-ai-sdk) — shows how to compose Anthropic's five agent patterns from framework primitives: `Pipeline`, `Concurrency::run()`, and plain loops. It's the right vocabulary, and this package keeps it.

What the official examples share is that they're **in-process, synchronous, and ephemeral**. Every one of them dies with the request: a failure at step 4 reruns steps 1–3, a deploy kills in-flight work, and nothing can pause for a human.

This page rewrites each official example with checkpoints, retry, and resume. Same patterns, same agents — durable.

## 1. Prompt chaining

**Official:** a `Pipeline` pipes one agent's output into the next. If the last step throws, everything reruns — every token from the earlier steps is paid for again.

**Durable:**

```php
// In ContentPipeline::build():
return $workflow
    ->step(OutlineAgent::class,
        prompt: fn ($s) => 'Outline an article about: '.$s->get('brief'))
    ->step(DraftAgent::class,
        prompt: fn ($s) => 'Write the article: '.$s->get('steps.OutlineAgent.text'))
    ->step(PolishAgent::class,
        prompt: fn ($s) => 'Polish this draft: '.$s->get('steps.DraftAgent.text'));

$run = AgentWorkflow::start('content-pipeline', input: ['brief' => $brief]);
```

Each step is a queued job, and state is committed after every step. When `PolishAgent` fails:

```php
$run->status;         // RunStatus::Failed
$run->failed_step;    // "PolishAgent"

$run->retry();        // re-runs PolishAgent only — outline and draft are already committed
```

The run's audit log (`$run->steps`) records every attempt of every step: input-state hash, output-state snapshot, token usage, timings, and the error that failed it.

## 2. Routing

**Official:** classify the request, then `match` on the classification — stateless, per request.

**Durable:**

```php
// In SupportTriage::build():
return $workflow
    ->step(ClassifyTicketAgent::class)
    ->when(fn (WorkflowState $s) => $s->get('steps.ClassifyTicketAgent.structured.urgent'),
        then: EscalationAgent::class,
        else: AutoReplyAgent::class)
    ->step(LogResolution::class);
```

The condition is evaluated against **checkpointed** state, the decision is recorded (`steps.{id}.branch`) for auditing, and the workflow continues sequentially after whichever branch ran.

## 3. Parallelization

**Official:** `Concurrency::run()` forks the work — and blocks the request until every branch returns. Process forking is also constrained on serverless (the original motivation for the declined `Ai::pool()` request, laravel/ai#323).

**Durable:**

```php
// In DueDiligence::build():
return $workflow
    ->step(FetchCompanyData::class)
    ->parallel([
        FinancialAnalysisAgent::class,
        LegalAnalysisAgent::class,
        NewsAnalysisAgent::class,
    ])
    ->step(SynthesisAgent::class);
```

Branches run as a **`Bus::batch`**: distributed across queue workers, SQS-safe. Each branch starts from the same state snapshot; results merge when all branches finish. Conflicting writes fail the run rather than silently losing data — or pass a `merge:` closure to resolve them. `mode: 'sync'` gives you the official in-request behavior behind the same API when that's genuinely what you want.

## 4. Orchestrator-workers

**Official:** return sub-agents from an agent's `tools()` method; the model orchestrates them.

**Durable:** unchanged — the SDK already does this well, and reinventing it would violate this package's design rules. Use sub-agents-as-tools *inside* an agent step; the step's checkpoint then covers the whole orchestration turn. What this package adds around it is the durability shell: the orchestrating step retries from its checkpoint, its token usage is audited, and a gated worker tool can pause the whole run (see below).

## 5. Evaluator-optimizer

**Official:** an in-memory `while` loop generating and critiquing until a score passes. Dies with the process; no record of iterations.

**Durable:**

```php
// In AdCopy::build():
return $workflow
    ->step(BriefAgent::class)
    ->evaluate(ReviseCopyAgent::class, as: 'revise',
        prompt: fn (WorkflowState $s) => 'Improve this copy and score your result 1-10: '
            .($s->get('steps.revise.structured.copy') ?? $s->get('steps.BriefAgent.text')),
        until: fn (WorkflowState $s) => $s->get('steps.revise.structured.score', 0) >= 8,
        maxIterations: 5)
    ->step(PublishCopy::class);
```

Every iteration is its own checkpointed job. A crash at iteration 3 resumes at iteration 3. After the loop, `steps.{id}.iteration` and `steps.{id}.satisfied` record how it ended.

## The sixth pattern the blog can't do: stop and wait

None of the official examples can pause. Durability makes waiting a first-class step:

```php
// In ContractReview::build():
return $workflow
    ->step(ExtractClausesAgent::class)
    ->step(RiskAnalysisAgent::class)
    ->awaitHuman(reason: 'Final sign-off required', schema: [
        'approved' => 'required|boolean',
        'notes' => 'nullable|string',
    ])
    ->step(GenerateSummaryAgent::class);
```

```php
// ...days later, after two deploys and a queue restart:
$run->resume(['approved' => true, 'notes' => 'LGTM'], by: $request->user());
```

The same mechanism absorbs the SDK's tool-approval flow: when an agent step pauses because a tool requires approval, the run parks as `awaiting_human` with the pending approvals persisted, and `resume(['toolu_1' => true])` replays the decisions into the paused conversation. `awaitEvent('payment.confirmed')` does the same for machine events.

## Summary table

| Pattern | Official mechanism | What durability adds |
| ------------------- | -------------------- | ------------------------------------------------------------- |
| Prompt chaining | `Pipeline` | Checkpoint per step; retry the failed step, not the workflow |
| Routing | `match` per request | Decision evaluated against, and recorded in, checkpointed state |
| Parallelization | `Concurrency::run()` | `Bus::batch` fan-out; queue-distributed; merge with conflicts |
| Orchestrator-workers | Sub-agents as tools | Unchanged — wrapped in a checkpointed, auditable step |
| Evaluator-optimizer | `while` loop | Every iteration checkpointed, capped, and audited |
| *(new)* Interrupts | — | `awaitHuman` / `awaitEvent` / tool-approval bridge |

Every example on this page has a corresponding passing test in [`tests/Feature`](../tests/Feature).
