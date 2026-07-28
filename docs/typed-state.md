# Typed workflow state

The `WorkflowState` bag is deliberately schemaless: it's a JSON checkpoint that any step can read and write, and unknown keys survive every round-trip — that's what lets a checkpoint written by one version of your app rehydrate under the next. The cost is stringly-typed access: paths like `steps.RiskAnalysisAgent.structured.riskScore` sprinkled through steps, prompts, and conditions, with no completion and no error when you typo one.

This page covers the two tools that fix the ergonomics without giving up the schemaless storage: `output()` for addressing step results, and per-workflow **state classes** for typed, semantic access.

## Step outputs without structural paths — `output()`

Every state instance can address a step's checkpointed output by class (or step id/alias) instead of a hand-spelled path:

```php
$state->output(RiskAnalysisAgent::class)?->structured('riskScore');  // instead of get('steps.RiskAnalysisAgent.structured.riskScore')
$state->output(DraftReplyAgent::class)?->text();                     // instead of get('steps.DraftReplyAgent.text')
$state->output('draft')?->text();                                    // steps defined with as: 'draft'
```

`output()` returns `null` when the step hasn't produced a checkpoint yet, so `?->` chains cleanly. On the returned object:

| Method | Returns |
| --- | --- |
| `text()` | The agent's text output, or `null`. |
| `structured()` | The full structured output array, or `null`. |
| `structured('riskScore')` | One field of the structured output (dot notation supported), with an optional second-argument default. |
| `get($key, $default)` | Anything else stored under the step's key (e.g. `conversation_id`). |
| `all()` | The raw array under `steps.{id}`. |

## Typed state classes — `stateClass()`

Instead of tracking the bag's structure along the way, a workflow can declare its own `WorkflowState` subclass — a typed lens over the same bag:

```php
namespace App\AgentWorkflows;

use App\Agents\ExtractClausesAgent;
use App\Agents\RiskAnalysisAgent;
use TimMcLeod\AgentWorkflows\WorkflowState;

class ContractReviewState extends WorkflowState
{
    public function contract(): string
    {
        return (string) $this->get('contract');
    }

    public function clauses(): ?string
    {
        return $this->output(ExtractClausesAgent::class)?->text();
    }

    public function riskScore(): int
    {
        return (int) $this->output(RiskAnalysisAgent::class)?->structured('riskScore', 0);
    }

    public function isHighRisk(): bool
    {
        return $this->riskScore() > 7;
    }
}
```

The workflow declares it once:

```php
class ContractReview extends Workflow
{
    public function stateClass(): string
    {
        return ContractReviewState::class;
    }

    public function build(WorkflowDefinition $workflow): WorkflowDefinition
    {
        return $workflow
            ->step(ExtractClausesAgent::class,
                prompt: fn (ContractReviewState $s) => "Extract the key clauses:\n\n".$s->contract())
            ->step(RiskAnalysisAgent::class,
                prompt: fn (ContractReviewState $s) => "Assess the risk of:\n\n".$s->clauses())
            ->when(fn (ContractReviewState $s) => $s->isHighRisk(),
                then: EscalationAgent::class,
                else: AutoApproveStep::class)
            ->awaitHuman(reason: 'Final sign-off required');
    }
}
```

From then on, **every** place the engine hands your code state — steps' `__invoke()`, prompt closures, `when()` conditions, `evaluate()` predicates, and continuations after `resume()`/`deliverEvent()`/`retry()` — receives `ContractReviewState`. Typed accessors, IDE completion, semantic names (`isHighRisk()` instead of a threshold comparison in three places), and one class that knows where things live. Plain-PHP steps can type-hint it directly:

```php
class SendReply
{
    public function __invoke(TicketReplyState $state): WorkflowState
    {
        $state->ticket()->sendReply($state->finalReply());

        return $state->set('sent', true);
    }
}
```

## What a state class is — and is not

A state class is a **lens, not a schema**. Storage, checkpointing, `input_state_hash` auditing, merge semantics, and the JSON in the `state` column are byte-for-byte identical with or without one. That has a few concrete consequences:

- **It's fully optional.** `stateClass()` defaults to `WorkflowState::class`; workflows that don't override it behave exactly as before. Adopting or dropping a state class later is never a data migration.
- **The base API stays available.** `get()`/`set()`/`has()`/`merge()` work on the subclass — accessors are the paved path, not a wall. Ad-hoc keys don't need ceremony.
- **Keep it stateless.** Accessors should read from the bag (`$this->get(...)`), not hold their own properties — anything outside the bag is not checkpointed and will not survive a queue hop.
- **It never strands runs.** The state class is deliberately excluded from the definition hash, so adding/renaming one won't trip strict [definition-drift protection](../README.md#deploys-and-definition-drift) for in-flight runs. And when a run's workflow isn't registered in the current process, hydration falls back to the base `WorkflowState` rather than failing.

## Why not typed properties?

The obvious alternative — a DTO with `public int $riskScore` reflection-serialized into the column — is deliberately not the design. Step outputs land under dynamic keys (`steps.{id}`, including aliases and repeated steps), the engine's merge operations (`parallel()` branch merges, `resume()`/`deliverEvent()` payloads) are defined on arrays, and strict property schemas reject the unknown keys that forward compatibility depends on. Accessors over the bag keep all of that intact and still give you the type-safety where it matters: at the call sites.
