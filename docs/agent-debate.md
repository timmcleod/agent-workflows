# Agent Debates

- [Introduction](#introduction)
- [Defining Debates](#defining-debates)
- [Retrieving Results](#retrieving-results)
- [Custom Protocol Prompts](#custom-protocol-prompts)
- [Custom Convergence](#custom-convergence)
- [Group-Chat Protocols](#group-chat-protocols)
- [Costs](#costs)
- [Retries](#retries)
- [Tool Approvals](#tool-approvals)
- [Transcript Storage](#transcript-storage)
- [Operational Sizing](#operational-sizing)
- [Testing](#testing)

## Introduction

The `debate` method lets two or more agents argue a topic in rounds — openings first, rebuttals after — while a judge rules on the transcript after every round. The loop stops when the judge finds consensus, when a custom predicate says so, or at the round cap. Every round is a durable checkpoint: a crash mid-debate resumes at the last committed round, not from the opening statements.

`debate` is sugar, not machinery. It compiles to an `evaluate` step whose body is a package-shipped callback, so the graph stays static and hashable, drift detection works unchanged, and the dashboard renders it like any other loop. The line the package keeps is **dynamic speakers inside a static graph** — no dynamic topology, no nested step types.

## Defining Debates

```php
// In AcquisitionReview::build():
return $workflow
    ->step(FetchFilingsStep::class)
    ->debate(
        ['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class],
        judge: VerdictAgent::class,
        as: 'thesis',
        topic: 'Should we acquire X? Filings: {{ filings }}'
    )
    ->step(WriteMemoStep::class);
```

**Debaters** (two or more) are ordinary SDK agent classes. String keys become speaker names in the transcript and prompts; without keys, the class basename is used. Speakers talk in array order, every round; each sees the topic, the transcript so far — including earlier speakers *this* round — and an opening or rebuttal protocol prompt.

**The judge** must implement `HasStructuredOutput`, and — under the default predicate — its schema must include a `consensus` boolean. The interface is checked at definition time; the `consensus` key is checked after the first verdict, so a judge with the wrong schema costs one round, not all of them.

**The `as` argument is required.** Debates are long-lived and expensive; an auto-generated positional id would silently renumber — moving state paths and the definition hash — when an earlier step is inserted.

**The `rounds` argument is a cap, not a promise.** It defaults to 3. Hitting it is an outcome rather than a failure: `steps.{id}.satisfied` is `false` and the run continues to the next step. Decide downstream what a hung jury means.

**The `topic` argument** accepts a closure over state or a plain string. Without one, the state's `prompt` key is used, like any agent step.

## Retrieving Results

You may read the debate's results downstream like any other step output:

```php
$state->output('thesis')?->get('judge.consensus');   // the final verdict
$state->output('thesis')?->get('judge.summary');     // whatever else your judge's schema holds
$state->output('thesis')?->get('satisfied');         // consensus reached, or cap hit?

Transcript::in($state, 'thesis')->render();          // the full argument, for a synthesis prompt
Transcript::in($state, 'thesis')->bySpeaker('bear'); // one side's entries
```

## Custom Protocol Prompts

The shipped prompts are deliberately short and neutral. You may override any of the three:

```php
->debate(
    ['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class],
    judge: VerdictAgent::class,
    as: 'thesis',
    openingPrompt: fn (WorkflowState $state, Transcript $transcript, string $speaker) => "...",
    rebuttalPrompt: fn (WorkflowState $state, Transcript $transcript, string $speaker) => "...",
    judgePrompt: fn (WorkflowState $state, Transcript $transcript) => "..."
)
```

> [!NOTE]
> The shipped prompts are versioned into the definition hash (`DebateRoundStep::PROTOCOL_VERSION`), so a package upgrade that changes them refuses to resume an in-flight debate under strict drift mode instead of silently altering the next round — the same contract as any other definition change. Your own prompt closures follow the existing closure rule: their bodies are not hashed.

## Custom Convergence

The default predicate is `judge.consensus === true`. You may replace it with the `until` argument to converge on anything the verdict holds — and with a custom `until`, the `consensus` schema requirement is waived entirely:

```php
->debate(
    [...],
    judge: ScoringJudge::class,
    as: 'thesis',
    until: fn (WorkflowState $state) => abs(
        $state->get('steps.thesis.judge.bullScore', 0) - $state->get('steps.thesis.judge.bearScore', 0)
    ) <= 1
)
```

## Group-Chat Protocols

Anything `debate` does not do — parallel openings, a router picking speakers, an exotic protocol — may be built from the same primitives it compiles to: an `evaluate` loop whose callback body drives the agents itself, appending to the transcript with `Support\Transcript`.

For example, to have a router agent pick who speaks next instead of round-robin, write your own round callback that prompts the router with the transcript and a roster, letting its structured choice decide which debater speaks. The graph stays static — the router is just another agent call inside the body.

> [!NOTE]
> This is deliberately not packaged as sugar. Router-selected speakers change the protocol surface — turn budgets per speaker, starvation, termination — in ways that should prove out in real workflows before the package freezes an API for them.

## Costs

Every debater is re-prompted with the growing transcript each round, so prompt tokens grow roughly with the *square* of the round count. Concretely, 4 rounds × 3 debaters is 12 debater turns, each carrying the full transcript so far (the round-4 prompts carry roughly 3 rounds × 3 speakers of argument each), plus 4 judge turns, each carrying the full transcript.

> [!WARNING]
> `rounds: 6` does not cost 50% more than `rounds: 4` — it costs roughly double.

Two levers control the budget:

- **`transcriptWindow: N`** renders only the last N rounds in *debater* prompts (the judge always sees everything — it rules on the whole debate). This caps per-turn prompt growth, at the price of debaters losing sight of early arguments.
- **A lower `rounds` cap.** Debates that converge usually converge early; treat the cap as a token budget and read `satisfied` downstream.

Per-round token usage — summed across all debater and judge calls — is recorded on each round's audit row.

## Retries

The round is the retry unit. Each round is one `evaluate` iteration — one checkpoint, one audit row. If anything inside a round throws (a debater, the judge, a provider timeout), the run fails at the debate step and `retry` re-runs **that round from its top**: committed rounds are never replayed, and the crashed round's partial transcript was never checkpointed, so there are no duplicate entries.

Inside a round, delivery is at-least-once — a retried round's debaters will answer again, and may answer differently. A judge failure therefore re-buys that round's debater turns; that is the price of no mid-round checkpoints, which keeps the retry story identical to every other step body.

An empty debater response is appended to the transcript as-is: throwing would waste the whole round on a transient blank, and skipping would desync the speaking order. The judge sees the hollow entry.

## Tool Approvals

If any debater (or the judge) pauses on SDK tool approvals mid-round, the round fails loudly naming the participant — nothing is checkpointed and no interrupt is parked. Parking would require replaying the human's decisions to *one speaker* while skipping those who already spoke this round; that per-speaker replay bookkeeping does not exist yet.

> [!WARNING]
> Move approval-gated tools out of debate participants: gather approvals in a `step` before the debate, or run the gated tool in a step after it.

## Transcript Storage

The transcript lives in the state bag, which means it is rewritten into every per-round checkpoint, appears in every audit row's state snapshot under the default `audit.step_output: full` (O(rounds²) storage across a debate), and is carried through every downstream step until the run completes. For debate-heavy workflows, you should set `audit.step_output: minimal` in the package config, and prune the transcript in a downstream step once it has served its purpose:

```php
class DistillThesisStep
{
    public function __invoke(WorkflowState $state): WorkflowState
    {
        $state->set('thesis_summary', $state->output('thesis')?->get('judge.summary'));

        return $state->forget('steps.thesis.transcript');
    }
}
```

## Operational Sizing

A debate round is the slowest step body this package ships: `debaters + 1` **sequential** agent calls, each slower than the last as the transcript grows. Two defaults can kill a perfectly healthy long round: the queue's `retry_after`, which redelivers the job if it elapses mid-round, and the sweeper's `sweep.stale_after` (default 600 seconds), past which the sweep declares the run stale and acts on it.

> [!WARNING]
> Size both `retry_after` and `sweep.stale_after` above your worst-case round duration — roughly `(debaters + 1) × your slowest single agent call` — and remember that later rounds run longer than round one. If your debates run long, raise both together.

## Testing

Fake the participants with the SDK. Array responses are consumed sequentially, so one array entry per round scripts the whole debate:

```php
$fake = AgentWorkflow::fake();

BullCaseAgent::fake(['Opening bull case.', 'Rebuttal.']);
BearCaseAgent::fake(['Opening bear case.', 'Concession.']);
VerdictAgent::fake([
    ['consensus' => false, 'summary' => 'Still apart.'],
    ['consensus' => true,  'summary' => 'Agreed: proceed.'],
]);

$run = AcquisitionReview::start(['filings' => '...']);

expect($run->state['steps']['thesis']['satisfied'])->toBeTrue();

$fake->assertStepRanTimes('thesis', 2); // rounds are step completions
```
