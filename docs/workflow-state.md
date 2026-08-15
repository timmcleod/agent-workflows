# Workflow State

- [Introduction](#introduction)
- [Working with State](#working-with-state)
- [Retrieving Step Output](#retrieving-step-output)
- [Typed State Classes](#typed-state-classes)
  - [Lenses, Not Schemas](#lenses-not-schemas)

## Introduction

Every run carries a `WorkflowState` bag — a JSON document checkpointed to the database after **every** step. It is how steps that run minutes or days apart share data: your `start` input is the initial state, each step reads what it needs and writes what it produces, and the run's checkpoint moves forward one step at a time.

## Working with State

At its simplest, workflow state is just keys:

```php
$state->get('ticket_id');
$state->set('sent', true);
$state->has('final_reply');
$state->forget('draft');
```

All four methods accept dot notation, `merge` and `all` operate on the whole bag, and everything stored must be JSON-serializable. A step reads a few keys, writes a few keys, and returns the state:

```php
class SendReply
{
    public function __invoke(WorkflowState $state): WorkflowState
    {
        $ticket = Ticket::findOrFail($state->get('ticket_id'));

        $ticket->sendReply($state->get('final_reply'));

        return $state->set('sent', true);
    }
}
```

Three conventions govern what lives where:

- Your `start` input is the initial state.
- Agent output lands under `steps.{step-id}` — `steps.DraftReplyAgent.text` for the response text, and `.structured` when the agent declares an output schema.
- `resume` and `deliverEvent` payloads merge into the top level.

So reading an agent's output is a dot-notation path like any other:

```php
$state->get('steps.DraftReplyAgent.text');
```

For many workflows, this is the whole story: input keys in, steps reading and writing keys, a few paths into agent output.

> [!NOTE]
> The bag is deliberately schemaless: it is a JSON checkpoint that any step may read and write, and unknown keys survive every round-trip — that is what lets a checkpoint written by one version of your application rehydrate under the next.

The rest of this page covers two optional refinements for when hand-spelled paths start to sprawl: the `output` method for addressing step results, and per-workflow state classes for typed, semantic access. Both are lenses over the same bag — adopting them changes nothing about how state is stored.

## Retrieving Step Output

Paths like `steps.RiskAnalysisAgent.structured.riskScore` work anywhere, but they offer no completion and no error when you typo one. Every state instance may instead address a step's checkpointed output by class name, step id, or alias:

```php
$state->output(RiskAnalysisAgent::class)?->structured('riskScore');  // instead of get('steps.RiskAnalysisAgent.structured.riskScore')
$state->output(DraftReplyAgent::class)?->text();                     // instead of get('steps.DraftReplyAgent.text')
$state->output('draft')?->text();                                    // steps defined with as: 'draft'
```

The `output` method returns `null` when the step has not produced a checkpoint yet, so `?->` chains cleanly. The returned object offers:

| Method | Returns |
| --- | --- |
| `text()` | The agent's text output, or `null`. |
| `structured()` | The full structured output array, or `null`. |
| `structured('riskScore')` | One field of the structured output (dot notation supported), with an optional second-argument default. |
| `get($key, $default)` | Anything else stored under the step's key (e.g. `conversation_id`). |
| `all()` | The raw array under `steps.{id}`. |

## Typed State Classes

Instead of tracking the bag's structure along the way, a workflow may declare its own `WorkflowState` subclass — a typed lens over the same bag:

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

The workflow declares it once, by overriding the `stateClass` method:

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
            ->step(
                ExtractClausesAgent::class,
                fn (ContractReviewState $state) => "Extract the key clauses:\n\n".$state->contract()
            )
            ->step(
                RiskAnalysisAgent::class,
                fn (ContractReviewState $state) => "Assess the risk of:\n\n".$state->clauses()
            )
            ->when(
                fn (ContractReviewState $state) => $state->isHighRisk(),
                then: EscalationAgent::class,
                else: AutoApproveStep::class
            )
            ->awaitHuman(reason: 'Final sign-off required');
    }
}
```

From then on, **every** place the engine hands your code state — step `__invoke` methods, prompt closures, `when` conditions, `evaluate` predicates, and continuations after `resume`, `deliverEvent`, or `retry` — receives an instance of `ContractReviewState`: typed accessors, IDE completion, semantic names (`isHighRisk()` instead of a threshold comparison repeated in three places), and one class that knows where things live. Plain-PHP steps may type-hint it directly:

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

### Lenses, Not Schemas

A state class is a lens, not a schema. Storage, checkpointing, `input_state_hash` auditing, merge semantics, and the JSON in the `state` column are byte-for-byte identical with or without one. A few concrete consequences follow:

- **State classes are fully optional.** The `stateClass` method defaults to `WorkflowState::class`, and workflows that do not override it behave exactly as before. Adopting or dropping a state class later is never a data migration.
- **The base API stays available.** `get`, `set`, `has`, and `merge` work on the subclass — accessors are the paved path, not a wall, and ad-hoc keys need no ceremony.
- **State classes never strand runs.** The state class is deliberately excluded from the definition hash, so adding or renaming one will not trip strict [definition-drift protection](defining-workflows.md#definition-drift) for in-flight runs. When a run's workflow is not registered in the current process, hydration falls back to the base `WorkflowState` rather than failing.

> [!WARNING]
> Keep state classes stateless. Accessors should read from the bag (`$this->get(...)`) rather than hold their own properties — anything outside the bag is not checkpointed and will not survive a queue hop.

> [!NOTE]
> The obvious alternative — a DTO with typed public properties, reflection-serialized into the state column — is deliberately not the design. Step outputs land under dynamic keys (`steps.{id}`, including aliases and repeated steps), the engine's merge operations (`parallel` branch merges, `resume` and `deliverEvent` payloads) are defined on arrays, and strict property schemas reject the unknown keys that forward compatibility depends on. Accessors over the bag keep all of that intact while giving you type-safety at the call sites, where it matters.
