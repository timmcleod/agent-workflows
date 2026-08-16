# Workflow State

- [Introduction](#introduction)
- [Working with State](#working-with-state)
- [Retrieving Step Output](#retrieving-step-output)
- [Typed State Classes](#typed-state-classes)
  - [Lenses, Not Schemas](#lenses-not-schemas)

## Introduction

Every run carries a `WorkflowState` bag: a JSON document checkpointed to the database after **every** step. It is how steps that run minutes or days apart share data. Your `start` input is the initial state, each step reads what it needs and writes what it produces, and the run's checkpoint moves forward one step at a time.

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
- Agent output lands under `steps.{step-id}`: `steps.DraftReplyAgent.text` for the response text, and `.structured` when the agent declares an output schema.
- `resume` and `deliverEvent` payloads merge into the top level.

Any of these keys may be pulled directly into an agent prompt with a `{{ placeholder }}` [template](agent-steps.md#strings-and-templates).

> [!NOTE]
> The bag is deliberately schemaless: it is a JSON checkpoint that any step may read and write, and unknown keys survive every round-trip. That is what lets a checkpoint written by one version of your application rehydrate under the next.

## Retrieving Step Output

Paths like `steps.RiskAnalysisAgent.structured.riskScore` work anywhere, but they offer no completion and no error when you typo one. Instead, you may address a step's checkpointed output by class name, step id, or alias:

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

The same addressing works inside string prompts as the [`{{ output:StepId }}` template form](agent-steps.md#strings-and-templates).

## Typed State Classes

Instead of tracking the bag's structure along the way, your workflow may declare its own `WorkflowState` subclass, a typed lens over the same bag:

```php
namespace App\AgentWorkflows;

use App\Agents\ExtractClausesAgent;
use App\Agents\RiskAnalysisAgent;
use TimMcLeod\AgentWorkflows\WorkflowState;

class ContractReviewState extends WorkflowState
{
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

Once you override the `stateClass` method, the typed instance reaches every place the engine hands your code state:

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
            ->step(ExtractClausesAgent::class, 'Extract the key clauses: {{ contract }}')
            ->step(
                RiskAnalysisAgent::class,
                fn (ContractReviewState $state) => "Assess the risk of:\n\n".$state->clauses()
            )
            ->when(
                fn (ContractReviewState $state) => $state->isHighRisk(),
                then: EscalationAgent::class
            )
            ->awaitHuman(reason: 'Final sign-off required');
    }
}
```

The typed instance flows to:

- step `__invoke` methods (type-hint the subclass directly)
- prompt closures and [conventional prompt methods](agent-steps.md#conventional-prompt-methods)
- `when` conditions and `evaluate` predicates
- continuations after `resume`, `deliverEvent`, and `retry`

### Lenses, Not Schemas

A state class is a lens, not a schema. Storage, checkpointing, auditing, and merge semantics are byte-for-byte identical with or without one:

- **Fully optional.** `stateClass` defaults to `WorkflowState::class`; adopting or dropping a state class later is never a data migration.
- **The base API stays available.** `get`, `set`, `has`, and `merge` work on the subclass; accessors are the paved path, not a wall.
- **Never strands runs.** The state class is excluded from the [definition hash](defining-workflows.md#definition-drift), and unregistered workflows hydrate the base `WorkflowState` rather than failing.

> [!WARNING]
> Keep state classes stateless. Accessors should read from the bag (`$this->get(...)`) rather than hold their own properties, since anything outside the bag is not checkpointed and will not survive a queue hop.
