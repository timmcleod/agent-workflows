# Defining Workflows

- [Introduction](#introduction)
- [Workflow Classes](#workflow-classes)
  - [Registration](#registration)
- [Steps](#steps)
  - [Callback Steps](#callback-steps)
  - [Step Ids & Aliases](#step-ids--aliases)
- [Conditions](#conditions)
- [Parallel Steps](#parallel-steps)
  - [Merging Branch State](#merging-branch-state)
- [Loops](#loops)
- [Gates & Debates](#gates--debates)
- [Definition Drift](#definition-drift)

## Introduction

A workflow is a class whose `build` method declares an ordered graph of steps. This page documents the definition API: sequential steps, conditions, parallel fan-outs, and loops. The waiting gates (`awaitHuman`, `awaitEvent`) are covered in [Human in the Loop](human-in-the-loop.md), and the `debate` method in [Agent Debates](agent-debate.md).

## Workflow Classes

You may generate a workflow class using the `make:agent-workflow` Artisan command:

```bash
php artisan make:agent-workflow ContractReview
```

Every workflow extends `Workflow` and describes its steps in the `build` method:

```php
namespace App\AgentWorkflows;

use TimMcLeod\AgentWorkflows\Workflow;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

class ContractReview extends Workflow
{
    public function build(WorkflowDefinition $workflow): WorkflowDefinition
    {
        return $workflow
            ->step(ExtractClausesAgent::class, prompt: $this->extractPrompt(...))
            ->step(RiskAnalysisAgent::class, prompt: $this->riskPrompt(...))
            ->awaitHuman(reason: 'Final sign-off required');
    }
}
```

A workflow registers under the kebab-cased class name (`ContractReview` → `contract-review`). You may override the `name` method to choose your own; runs store this name, so you should treat it as stable once runs exist.

You may start a run using the workflow's static `start` method, or via the facade — `AgentWorkflow::start` accepts the class name or the registered string name:

```php
$run = ContractReview::start(['contract' => $text], participant: $user);
```

You may also override the `stateClass` method to hydrate a [typed state class](workflow-state.md#typed-state-classes) for every step of the workflow.

### Registration

List your workflow classes in the `workflows` array of `config/agent-workflows.php`:

```php
'workflows' => [
    App\AgentWorkflows\ContractReview::class,
],
```

Definitions are registered at boot on every process because queue workers need them too — a worker picking up step 2 must be able to look up what step 3 is. For runtime registration (tests, packages), use `AgentWorkflow::register`.

Registering a *different* definition under an existing name throws rather than silently overwriting; `WorkflowRegistry::forget` is the explicit escape hatch.

## Steps

The `step` method appends a unit of work. Agent classes become [agent steps](agent-steps.md); any other invokable class becomes a callback step. Steps run in the order they are added:

```php
return $workflow
    ->step(ClassifyTicketAgent::class)
    ->step(LogResolution::class);
```

Agent steps take an optional `prompt` argument — a string, a closure over the state, or a first-class callable — covered in [Agent Steps](agent-steps.md#prompts).

Step targets must be real classes — a typo'd class string throws at definition time rather than becoming a callback step that explodes inside a queue worker.

Every step-declaring method (`step`, `when`, `parallel`, `evaluate`, `debate`, `awaitHuman`, `awaitEvent`) also accepts an optional `label` — a human-facing description surfaced by [`$run->progress()`](runs-and-observability.md#run-progress) for live progress displays:

```php
->step(GatherTicketContext::class, label: 'Reading the full message thread')
->parallel([...], label: 'Analyzing attachments and prior tickets')
```

Unlabeled steps get sensible defaults: class-based ids humanize (`GatherTicketContext` becomes "Gather ticket context"), structural steps get purpose-built descriptions ("Running parallel branches", "Evaluating a condition"), and `awaitHuman` falls back to its `reason`. Labels are cosmetic — like `awaitHuman` reasons, they are excluded from the [definition hash](#definition-drift), so adding or editing them never strands in-flight runs in strict drift mode.

### Callback Steps

Callback steps are plain invokable classes. They receive the current state and must return the state (or `null` to leave it unchanged — returning anything else fails the step):

```php
class LogResolution
{
    public function __invoke(WorkflowState $state): WorkflowState
    {
        Resolution::create(['ticket_id' => $state->get('ticket_id')]);

        return $state->set('logged', true);
    }
}
```

A callback may also declare its `StepDefinition` as an optional second parameter — to read its own id or configuration — and may return a `StepResult` to report token usage on its audit row.

### Step Ids & Aliases

Every step gets an id, used in state paths (`steps.{id}`), audit rows, and `output()` lookups. Derived ids are the class basename; using the same class twice dedupes with a numeric suffix (`TransformStep`, `TransformStep:2`). You may name a step explicitly with the `as` argument:

```php
->step(TransformStep::class, as: 'double')
->step(TransformStep::class, as: 'double-again')
```

An explicit alias that collides with an existing id throws — silently renaming it would point audit rows, state paths, and `output()` lookups at the wrong step.

> [!NOTE]
> Structural steps without an `as` get positional default ids (`when:2`, `parallel:3`, `await-human:4`). Inserting an earlier step renumbers them — which moves state paths and changes the [definition hash](#definition-drift). For long-lived workflows, prefer explicit aliases on structural steps.

## Conditions

The `when` method branches at runtime. When the condition holds, the `then` step runs; otherwise the `else` step runs — or, with no `else`, the run skips straight to the next sequential step. The workflow continues sequentially after whichever branch ran, so both paths converge:

```php
return $workflow
    ->step(ClassifyTicketAgent::class)
    ->when(fn (WorkflowState $state) => $state->output(ClassifyTicketAgent::class)?->structured('urgent'),
        then: EscalationAgent::class,
        else: AutoReplyAgent::class)
    ->step(LogResolution::class);
```

Agent branch targets take their prompts from the `thenPrompt` and `elsePrompt` arguments.

The condition is evaluated against checkpointed state, and the decision is recorded for audit: the condition step checkpoints `steps.{id}.branch` with the chosen branch's step id — or `'skipped'` when a false condition had no `else` — and gets its own audit row like any other step. A condition closure that throws fails the run at the condition step, retryable like any other failure.

## Parallel Steps

The `parallel` method fans out into concurrent branches, each starting from the same state snapshot, then merges the branch states and continues:

```php
return $workflow
    ->step(FetchCompanyData::class)
    ->parallel([
        FinancialAnalysisAgent::class,
        LegalAnalysisAgent::class,
        NewsAnalysisAgent::class,
    ])
    ->step(SynthesisAgent::class);
```

String keys become branch step aliases. Two modes are available:

- `batch` (default) runs branches as a queued `Bus::batch` — distributed across queue workers, durable, SQS-safe.
- `sync` runs branches in-process via `Concurrency::run`, for when in-request behavior is genuinely what you want.

If any branch fails, the run fails at the parallel step, and `retry` re-runs the whole fan-out.

> [!WARNING]
> Approval-gated agents are not supported inside parallel branches — a branch that pauses on [tool approvals](agent-steps.md#tool-approvals) fails the run with an explicit error. Keep approval-gated agents in sequential steps before or after the fan-out.

### Merging Branch State

The default merge is safe by construction: agent checkpoints (`steps.*`) merge per step id, so agent branches never conflict on the engine's own bookkeeping, and everything else is a **union of branch writes**. Two branches writing different values to the same key fail the run rather than silently losing data, and a key a branch `forget`s is not deleted from the merged state.

For deletions, or your own conflict policy, provide a `merge` closure — it receives each branch's resulting state keyed by branch id, along with the snapshot the branches started from, and returns the merged result as an array or a `WorkflowState`:

```php
->parallel(
    [BullCaseAgent::class, BearCaseAgent::class],
    merge: fn (array $branches, array $input) => array_merge($input, [
        'thesis' => $branches['BullCaseAgent']['thesis'].' vs '.$branches['BearCaseAgent']['thesis'],
    ]),
)
```

## Loops

The `evaluate` method runs a target repeatedly until a predicate holds or a cap is reached — the evaluator-optimizer pattern. Every iteration is its own checkpointed job and audit row, so a crash at iteration 3 resumes at iteration 3:

```php
return $workflow
    ->step(BriefAgent::class)
    ->evaluate(ReviseCopyAgent::class, as: 'revise',
        prompt: fn (WorkflowState $state) => 'Improve this copy and score your result 1-10: '
            .($state->get('steps.revise.structured.copy') ?? $state->get('steps.BriefAgent.text')),
        until: fn (WorkflowState $state) => $state->get('steps.revise.structured.score', 0) >= 8,
        maxIterations: 5)
    ->step(PublishCopy::class);
```

An agent target takes its per-iteration prompt from the `prompt` argument. `maxIterations` defaults to 3 and must be at least 1. Hitting the cap is an outcome, not a failure: after the loop, `steps.{id}.iteration` and `steps.{id}.satisfied` record how it ended, and the run continues to the next step.

Without an `as`, an `evaluate` step's id is the bare class basename — the same id a plain `step` would get — so `output(Target::class)` addresses the loop's checkpoints exactly like any other step's.

## Gates & Debates

Two families of step types have pages of their own:

- **`awaitHuman` and `awaitEvent`** park the run for a person or another system — validation schemas, SLA timeouts, and payload security are covered in [Human in the Loop](human-in-the-loop.md).
- **`debate`** runs judge-ruled, multi-agent argument rounds as a durable loop — see [Agent Debates](agent-debate.md).

## Definition Drift

Every run stores a hash of its definition at start time. If a deploy changes the workflow while a run is in flight, resuming it is refused by default (`definition_drift: strict`) so a run never executes against steps it never agreed to. Set the option to `loose` to resume best-effort by step name.

Closure bodies — conditions, predicates, merge strategies, and prompt closures — are not hashed; only the step structure is.
