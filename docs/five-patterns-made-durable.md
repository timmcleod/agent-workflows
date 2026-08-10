# The five patterns, made durable

Laravel's official guidance — [Building Multi-Agent Workflows with the Laravel AI SDK](https://laravel.com/blog/building-multi-agent-workflows-with-the-laravel-ai-sdk) — shows how to compose Anthropic's five agent patterns from framework primitives: `Pipeline`, `Concurrency::run()`, and plain loops. It's the right vocabulary, and this package keeps it.

What the official examples share is that they're **in-process, synchronous, and ephemeral**. Every one of them dies with the request: a failure at step 4 reruns steps 1–3, a deploy kills in-flight work, and nothing can pause for a human.

This page rewrites each official example with checkpoints, retry, and resume. Same patterns, same agents — durable. (This is an article, not a reference: the methods it uses are documented in [Defining Workflows](defining-workflows.md).)

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

$run = ContentPipeline::start(['brief' => $brief]);
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

Branches run as a **`Bus::batch`**: distributed across queue workers, SQS-safe. Each branch starts from the same state snapshot; results merge when all branches finish. `mode: 'sync'` gives you the official in-request behavior behind the same API when that's genuinely what you want.

Two things to know about the default merge: agent checkpoints (`steps.*`) merge per step id, so agent branches never conflict on the engine's own bookkeeping; and the merge is a **union of branch writes** — a key a branch `forget()`s is not deleted from the merged state, and two branches writing different values to the same key fail the run rather than silently losing data. For deletions, or your own conflict policy, provide a merge closure:

```php
->parallel(
    [BullCaseAgent::class, BearCaseAgent::class],
    merge: fn (array $branches, array $input) => array_merge($input, [
        'thesis' => $branches['BullCaseAgent']['thesis'].' vs '.$branches['BearCaseAgent']['thesis'],
    ]),
)
```

If any branch fails, the run fails at the parallel step and `retry()` re-runs the whole fan-out.

The same act-when-everything-finishes semantics exist one level up, across whole runs: start several runs into a [run group](runs-and-observability.md#run-groups) and a `WorkflowGroupSettled` event fires when the last one reaches a terminal status — `Bus::batch` semantics at the run level.

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

## A pattern of this package's own: debate — `debate()`

Two or more agents argue a topic in rounds while a judge rules on the transcript after each round; the loop stops on consensus or at the round cap. Sugar over `evaluate()` — every round is a checkpoint and an audit row, and a crash mid-debate resumes at the last committed round:

```php
// In AcquisitionReview::build():
return $workflow
    ->step(FetchFilingsStep::class)
    ->debate(
        ['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class],
        judge: VerdictAgent::class,
        as: 'thesis',
        rounds: 4,
        topic: fn (WorkflowState $s) => 'Should we acquire X? Filings: '.$s->get('filings'),
    )
    ->step(WriteMemoStep::class);
```

The judge needs structured output with a `consensus` boolean (or pass your own `until:`); downstream steps read the verdict via `$state->output('thesis')?->get('judge.consensus')` and the argument via `Transcript::in($state, 'thesis')`. Costs grow quadratically with `rounds` — the full story (custom protocol prompts, `transcriptWindow:`, retry semantics, operational sizing) lives in **[Agent Debates](agent-debate.md)**.

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
| *(new)* Debate | — | Judge-ruled rounds, one checkpoint each — [`debate()`](agent-debate.md) |
| *(new)* Interrupts | — | `awaitHuman` / `awaitEvent` / tool-approval bridge |

Every example on this page has a corresponding passing test in [`tests/Feature`](../tests/Feature).
