# Agent Workflows for Laravel

**Durable, resumable, human-interruptible agent workflows on top of the [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk).**

The official Laravel guidance shows how to compose the five multi-agent patterns (prompt chaining, routing, parallelization, orchestrator-workers, evaluator-optimizer) with framework primitives — `Pipeline`, `Concurrency::run()`, plain loops. All of it is in-process and ephemeral: a failure at step 4 reruns steps 1–3, nothing survives a deploy, and there is no way to pause for a human and continue tomorrow.

This package makes those same patterns **crash-safe** on the substrate Laravel already ships: queues, batches, retries, and Horizon.

- **Checkpointed** — workflow state is persisted after every step. A failed step retries *from that step*, not from the beginning.
- **Resumable** — runs survive crashes, deploys, and queue restarts.
- **Interruptible** — `awaitHuman()` parks a run for hours or days; `resume()` validates the human's input and continues. SDK tool-approval pauses surface as workflow interrupts too.
- **Observable** — every step is a queued job (visible in Horizon), every run and step is a queryable Eloquent record, and lifecycle events fire throughout.

> **Status: pre-release.** The core engine (sequential, conditional, parallel, evaluator steps; checkpoint/retry; interrupts; agent handoffs; events; testing fakes) is implemented and tested. APIs may change before 1.0.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `laravel/ai` ^0.10

## Installation

```bash
composer require timmcleod/agent-workflows

php artisan vendor:publish --tag=agent-workflows-config
php artisan migrate
```

## Defining and starting a workflow

Define a workflow once (in a service provider's `boot()`), giving each step either a **Laravel AI agent class** or any **invokable class**:

```php
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;

AgentWorkflow::define('contract-review')
    ->start(ExtractClausesAgent::class)
    ->then(RiskAnalysisAgent::class)
    ->then(GenerateSummaryAgent::class);
```

Start a run from anywhere — a controller, a command, a listener:

```php
$run = AgentWorkflow::start('contract-review', input: ['document_id' => $doc->id]);

$run->status;        // RunStatus::Pending — steps execute as queued jobs
$run->id;            // ULID, safe to hand to your frontend for polling
```

Each step runs as one queued job. The job payload carries only IDs — state is loaded fresh from the checkpoint, so a retried step never sees stale data.

### Workflow state

A `WorkflowState` bag flows through the run and is checkpointed to the database after **every** step. Callback steps receive it and mutate it:

```php
use TimMcLeod\AgentWorkflows\WorkflowState;

class NormalizeDocument
{
    public function __invoke(WorkflowState $state): WorkflowState
    {
        return $state->set('document.text', strip_tags($state->get('document.raw')));
    }
}
```

`get()`/`set()`/`has()`/`forget()` accept dot notation; `merge()` and `all()` work on the whole bag. Everything stored must be JSON-serializable.

## The five patterns, made durable

### 1. Prompt chaining — `then()`

```php
AgentWorkflow::define('content-pipeline')
    ->start(OutlineAgent::class)
    ->then(DraftAgent::class)
    ->then(PolishAgent::class);
```

Unlike a `Pipeline`, every arrow in that chain is a checkpoint. **If `PolishAgent` fails, you retry `PolishAgent`** — the outline and draft are already committed:

```php
$run->status;         // RunStatus::Failed
$run->failed_step;    // "PolishAgent"
$run->failure_reason; // the exception message

$run->retry();        // re-dispatches PolishAgent only
```

### 2. Routing — `when()`

Branch on checkpointed state at runtime. The workflow continues sequentially after whichever branch ran:

```php
AgentWorkflow::define('support-triage')
    ->start(ClassifyTicketAgent::class)
    ->when(fn (WorkflowState $s) => $s->get('steps.ClassifyTicketAgent.structured.urgent'),
        then: EscalationAgent::class,
        else: AutoReplyAgent::class)
    ->then(LogResolution::class);
```

Omit `else:` to simply skip ahead when the condition is false. The decision is recorded in state under `steps.{id}.branch` for auditing.

### 3. Parallelization — `parallel()`

Fan out into concurrent branches, each starting from the same state snapshot, then merge and continue:

```php
AgentWorkflow::define('due-diligence')
    ->start(FetchCompanyData::class)
    ->parallel([
        FinancialAnalysisAgent::class,
        LegalAnalysisAgent::class,
        NewsAnalysisAgent::class,
    ])
    ->then(SynthesisAgent::class);
```

By default branches run as a **`Bus::batch`** — durable, distributed across your queue workers, visible in Horizon. Pass `mode: 'sync'` to run them in-process via `Concurrency::run()` for request-lifetime fan-outs.

Branch states are merged automatically; if two branches write different values to the same key, the run fails rather than silently losing data. Resolve conflicts with your own strategy:

```php
->parallel(
    [BullCaseAgent::class, BearCaseAgent::class],
    merge: fn (array $branches, array $input) => array_merge($input, [
        'thesis' => $branches['BullCaseAgent']['thesis'].' vs '.$branches['BearCaseAgent']['thesis'],
    ]),
)
```

If any branch fails, the run fails at the parallel step and `retry()` re-runs the whole fan-out.

### 4. Orchestrator-workers

The SDK already does this well — return sub-agents from an agent's `tools()` method and the provider drives them. Use that *inside* an agent step; this package adds nothing on top, deliberately.

### 5. Evaluator-optimizer — `evaluate()`

Loop a step until a predicate on state is satisfied, with a hard iteration cap. Every iteration is checkpointed:

```php
AgentWorkflow::define('ad-copy')
    ->start(BriefAgent::class)
    ->evaluate(ReviseCopyAgent::class,
        until: fn (WorkflowState $s) => $s->get('steps.CritiqueAgent.structured.score', 0) >= 8,
        maxIterations: 5)
    ->then(PublishCopy::class);
```

After the loop, `steps.{id}.iteration` holds the count and `steps.{id}.satisfied` records whether the predicate passed or the cap was hit.

## Human-in-the-loop

### Pause for a human — `awaitHuman()`

Park a run until someone signs off. The interrupt persists a reason and an optional response schema (Laravel validation rules), so your approval UI knows exactly what to collect:

```php
AgentWorkflow::define('contract-review')
    ->start(ExtractClausesAgent::class)
    ->then(RiskAnalysisAgent::class)
    ->awaitHuman(reason: 'Final sign-off required', schema: [
        'approved' => 'required|boolean',
        'notes' => 'nullable|string',
    ])
    ->then(GenerateSummaryAgent::class);
```

The run parks as `awaiting_human` — for minutes or for weeks, across deploys and queue restarts. Resume it whenever the human responds:

```php
$run->resume(['approved' => true, 'notes' => 'LGTM'], by: $request->user());
```

The payload is validated against the schema (a `ValidationException` leaves the run parked), merged into state for the steps that follow, and the resolution — payload, who resolved it, when — is recorded on the interrupt for audit.

### Wait for an application event — `awaitEvent()`

Park a run until something happens elsewhere in your system:

```php
AgentWorkflow::define('order-flow')
    ->start(PrepareOrderAgent::class)
    ->awaitEvent('payment.confirmed')
    ->then(FulfillmentAgent::class);
```

```php
// e.g. in your payment webhook controller:
$run->deliverEvent('payment.confirmed', ['amount' => $payment->amount]);
```

Delivering the wrong event name throws; the payload is merged into state.

### SDK tool approvals become workflow interrupts

`laravel/ai` tools can [require approval](https://laravel.com/docs/13.x/ai-sdk) before they run. When an agent step pauses on tool approvals, this package converts the pause into a workflow interrupt: the run parks as `awaiting_human` with the pending approvals (tool, arguments, reason) persisted on the interrupt. Resume with a decisions map and the package replays it into the paused conversation:

```php
$run = AgentWorkflow::start('deploy', input: ['prompt' => 'Deploy the app']);

$run->status;                          // awaiting_human
$run->interrupts->last()->context;     // ['approvals' => [['id' => 'toolu_1', 'tool' => 'deploy_tool', ...]]]

$run->resume(['toolu_1' => true]);     // true / false / Decision::edit([...]) per tool call
```

The agent must remember conversations (the SDK requires that to pause); decisions are checkpointed before replay, so a crash mid-resume replays them safely on retry.

## Handoffs

Persistent conversation routing between agents — the piece the SDK's sub-agents-as-tools pattern doesn't cover, because sub-agents answer *one* question and return. A handoff transfers *ownership of the whole conversation*, so the customer's next message (an hour or a week later) lands with the right agent.

Declare who an agent may hand off to, and expose the generated `transfer_to_*` tools:

```php
use TimMcLeod\AgentWorkflows\Concerns\HasHandoffTools;
use TimMcLeod\AgentWorkflows\Contracts\HasHandoffs;

class TriageAgent implements Agent, HasHandoffs, HasTools, RemembersConversations
{
    use HasHandoffTools, Promptable, RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'Triage the customer request and transfer to a specialist when appropriate.';
    }

    public function handoffs(): array
    {
        return [RefundsAgent::class, BillingAgent::class];
    }

    public function tools(): iterable
    {
        return [...$this->handoffTools(), new LookupOrderTool];
    }
}
```

The agent now sees `transfer_to_refunds_agent` and `transfer_to_billing_agent` tools (a target can customize its pitch with a `handoffDescription()` method). When the model calls one, the package records the target as the conversation's owner — no SDK changes, just an event listener watching for the synthetic tool calls.

On the next user turn, route to whoever owns the conversation:

```php
$agent = AgentWorkflow::resolveAgentFor($conversationId, default: TriageAgent::class);

$response = $agent->continue($conversationId, $user)->prompt($request->input('message'));
```

Transfers can also be made manually (an operator reassigning a conversation), and every transfer fires `ConversationTransferred` with the old and new owner:

```php
AgentWorkflow::transferConversation($conversationId, RefundsAgent::class);
```

## Agent steps

Any `laravel/ai` agent class can be a step. The step needs a prompt — either implement `HasWorkflowPrompt` to build it from state:

```php
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use TimMcLeod\AgentWorkflows\Contracts\HasWorkflowPrompt;
use TimMcLeod\AgentWorkflows\WorkflowState;

class RiskAnalysisAgent implements Agent, HasWorkflowPrompt
{
    use Promptable;

    public function instructions(): string
    {
        return 'Analyze the risk of the given contract.';
    }

    public function workflowPrompt(WorkflowState $state): string
    {
        return 'Analyze: '.$state->get('document.text');
    }
}
```

…or put a string under the state's `prompt` key and the agent will use that.

After the step runs, its output is checkpointed under `steps.{step id}`:

```php
$run->state['steps']['RiskAnalysisAgent']['text'];        // the response text
$run->state['steps']['RiskAnalysisAgent']['structured'];  // structured output, if the agent declares a schema
```

Token usage from every agent response is recorded on the step's audit row.

## Inspecting runs

Runs, steps, and interrupts are plain Eloquent models:

```php
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;

$run = WorkflowRun::find($id);

$run->status;          // pending | running | awaiting_human | failed | completed | cancelled
$run->current_step;    // the cursor
$run->state;           // the latest checkpoint (array)
$run->steps;           // audit log: every attempt of every step, with
                       // input-state hash, output-state snapshot, token usage,
                       // timings, and errors
```

Associate a run with a user (or any model) via the polymorphic participant:

```php
AgentWorkflow::start('contract-review', input: [...], participant: $user);
```

## Events

Listen for lifecycle events anywhere you'd listen for any Laravel event:

```php
use TimMcLeod\AgentWorkflows\Events\StepCompleted;
use TimMcLeod\AgentWorkflows\Events\WorkflowCompleted;
use TimMcLeod\AgentWorkflows\Events\WorkflowFailed;
use TimMcLeod\AgentWorkflows\Events\WorkflowStarted;

Event::listen(WorkflowFailed::class, function (WorkflowFailed $event) {
    Notification::send($ops, new WorkflowFailedNotification($event->run, $event->exception));
});
```

`StepCompleted` fires per step (including parallel branches) and carries the audit row — token usage included — which makes cost accounting a one-listener job.

## Class-based definitions

Prefer classes over closures in a provider? Generate one:

```bash
php artisan make:agent-workflow ContractReview
```

```php
namespace App\AgentWorkflows;

use TimMcLeod\AgentWorkflows\Workflow;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

class ContractReview extends Workflow
{
    public function build(WorkflowDefinition $workflow): WorkflowDefinition
    {
        return $workflow
            ->start(ExtractClausesAgent::class)
            ->then(RiskAnalysisAgent::class);
    }
}
```

List it in `config/agent-workflows.php` so **queue workers** know the definition too:

```php
'workflows' => [
    App\AgentWorkflows\ContractReview::class,
],
```

It registers under the kebab-cased class name (`contract-review`), and you can start it by name or by class.

## Deploys and definition drift

Every run stores a hash of its definition at start time. If a deploy changes the workflow while a run is in flight, resuming it is refused by default (`definition_drift: strict`) so a run never executes against steps it never agreed to. Set `loose` to resume best-effort by step name.

## Testing

Record lifecycle assertions with `AgentWorkflow::fake()` — workflows still execute, so fake the agents themselves with the SDK's `Agent::fake()`:

```php
it('reviews contracts', function () {
    $fake = AgentWorkflow::fake();

    ExtractClausesAgent::fake(['Clause list…']);
    RiskAnalysisAgent::fake([['riskScore' => 9]]);

    $this->post('/contracts/review', ['document_id' => $doc->id]);

    $fake->assertStarted('contract-review', fn ($run) => $run->state['document_id'] === $doc->id);
    $fake->assertStepRan(RiskAnalysisAgent::class);
    $fake->assertCompleted('contract-review');
});
```

Also available: `assertNotStarted()`, `assertNothingStarted()`, `assertStepDidNotRun()`, `assertFailed()`. With the default `sync` queue in tests, an entire workflow executes inside the `start()` call — no worker needed.

## Configuration

`config/agent-workflows.php`:

| Key                                                  | What it does                                                                 |
| ---------------------------------------------------- | ---------------------------------------------------------------------------- |
| `workflows`                                          | Class-based workflows to register at boot (workers included).               |
| `queue.connection` / `queue.queue`                   | Route step jobs onto their own connection/queue (recommended with Horizon). |
| `tables.*`                                           | Rename the package's tables (runs, steps, interrupts, conversation owners). |
| `definition_drift`                                   | `strict` (refuse to resume a changed definition) or `loose` (by step name). |

## What this package is not

- **Not an arbitrary graph engine.** Sequential + conditional + parallel + loop + interrupt covers the overwhelming majority of production workflows. No cycles-with-reducers, no time-travel debugging.
- **Not a group-chat / free-form agent-debate framework.** The SDK's orchestrator-workers (sub-agents as tools) plus handoffs cover the useful cases.
- **Not a fork or patch of `laravel/ai`.** It composes the SDK's public API only, behind a single adapter seam.

## License

MIT
