# Five patterns, made durable

The Laravel blog's [Building Multi-Agent Workflows with the Laravel AI SDK](https://laravel.com/blog/building-multi-agent-workflows-with-the-laravel-ai-sdk) shows how to compose Anthropic's five agent patterns from framework primitives: `Pipeline`, `Concurrency::run()`, and plain loops. It's the right vocabulary, and this package keeps it.

What the blog's examples share is that they're **in-process, synchronous, and ephemeral**: a failure at step 4 reruns steps 1–3 (and re-bills their tokens), a deploy kills in-flight work, and nothing can pause for a human. This page maps each pattern to its durable counterpart. The linked reference sections carry the details.

## 1. Prompt chaining

The blog's `Pipeline` pipes one agent's output into the next. Durably, each link is a checkpointed queued [step](defining-workflows.md#steps), chained through `{{ output:... }}` templates:

```php
// In ContentPipeline::build():
return $workflow
    ->step(OutlineAgent::class, 'Outline an article about: {{ brief }}')
    ->step(DraftAgent::class, 'Write the article: {{ output:OutlineAgent }}')
    ->step(PolishAgent::class, 'Polish this draft: {{ output:DraftAgent }}');
```

When `PolishAgent` fails, `$run->retry()` re-runs only `PolishAgent`: the outline and draft are already committed, their token bill already paid, and every attempt is on the [audit log](runs-and-observability.md#inspecting-runs).

## 2. Routing

The blog's version classifies a request and `match`es on the result, statelessly. Durably, [`when()`](defining-workflows.md#conditions) branches on **checkpointed** state, records the decision for audit, and converges both paths onto the next step:

```php
->step(ClassifyTicketAgent::class, 'Classify this ticket: {{ ticket }}')
->when(
    fn (WorkflowState $state) => $state->output(ClassifyTicketAgent::class)?->structured('urgent'),
    then: EscalationAgent::class,
    else: AutoReplyAgent::class
)
```

## 3. Parallelization

`Concurrency::run()` forks the work and blocks the request until every branch returns. Durably, [`parallel()`](defining-workflows.md#parallel-steps) runs branches as a queued `Bus::batch`: distributed across workers, SQS-safe, each branch carrying [its own prompt](defining-workflows.md#parallel-steps), with [merge semantics](defining-workflows.md#merging-branch-state) that refuse to silently lose data:

```php
->parallel([
    [FinancialAnalysisAgent::class, 'Assess the financials: {{ target }}'],
    [LegalAnalysisAgent::class, 'Assess the legal exposure: {{ target }}'],
])
->step(SynthesisAgent::class, 'Combine both analyses into one memo.')
```

The same act-when-everything-finishes shape exists across whole runs via [run groups](runs-and-observability.md#run-groups).

## 4. Orchestrator-workers

Unchanged: the SDK already does this well. You may return sub-agents from an agent's `tools()` method and orchestrate inside a single [agent step](agent-steps.md#tools). The step's checkpoint then covers the whole orchestration turn, with retry, audit, and token accounting around it:

```php
// ResearchAgent's tools() returns its worker agents. The whole
// orchestration turn is one checkpointed step:
->step(ResearchAgent::class, 'Research this company: {{ company }}')
```

## 5. Evaluator-optimizer

The blog's in-memory `while` loop dies with the process and records nothing. Durably, [`evaluate()`](defining-workflows.md#loops) checkpoints every iteration: a crash at iteration 3 resumes at iteration 3, and the loop's outcome (`iteration`, `satisfied`) is state like everything else:

```php
->evaluate(
    ReviseCopyAgent::class,
    as: 'revise',
    until: fn (WorkflowState $state) => $state->get('steps.revise.structured.score', 0) >= 8,
    maxIterations: 5
)
```

## A pattern of this package's own: debate

Two or more agents argue a topic in rounds while a judge rules on the transcript after each. Every round is a checkpoint, and a crash resumes at the last committed round:

```php
->debate(
    ['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class],
    judge: VerdictAgent::class,
    as: 'thesis',
    topic: 'Should we acquire {{ target }}?'
)
```

See [Agent Debates](agent-debate.md) for judges, convergence, and costs.

## And the pattern none of them have: stop and wait

The durable substrate enables the one thing no in-process pattern can do: park mid-process for a person ([`awaitHuman()`](human-in-the-loop.md#awaiting-human-input)) or another system ([`awaitEvent()`](human-in-the-loop.md#awaiting-application-events)), for hours or weeks, through deploys, then resume exactly where the run left off:

```php
->step(RiskAnalysisAgent::class, 'Assess the risks: {{ contract }}')
->awaitHuman(reason: 'Final sign-off required')
->step(GenerateSummaryAgent::class)
```

```php
// hours or weeks later:
$run->resume(['approved' => true], by: $request->user());
```

SDK [tool approvals](human-in-the-loop.md#tool-approvals) ride the same mechanism.

## Summary

| Pattern | In-process primitive | Durable counterpart |
| --- | --- | --- |
| Prompt chaining | `Pipeline` | [`step()`](defining-workflows.md#steps) chains with checkpoints between links |
| Routing | `match` on a classification | [`when()`](defining-workflows.md#conditions) on checkpointed state, decision audited |
| Parallelization | `Concurrency::run()` | [`parallel()`](defining-workflows.md#parallel-steps) as a queued `Bus::batch` |
| Orchestrator-workers | sub-agents as tools | the same, inside one checkpointed [agent step](agent-steps.md#tools) |
| Evaluator-optimizer | in-memory `while` | [`evaluate()`](defining-workflows.md#loops) with per-iteration checkpoints |
| Debate | (none) | [`debate()`](agent-debate.md), judge-ruled rounds as a durable loop |
| Stop and wait | (none) | [`awaitHuman()` / `awaitEvent()`](human-in-the-loop.md) |
