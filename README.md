# Agent Workflows for Laravel

**The [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk) is how your app talks to an AI. This package is how your app runs a *process* that involves AI: several steps, decisions, waiting on people, and picking up where it left off.**

Picture a real feature: reviewing a contract. Extract the clauses, score the risk, escalate if the risk is high, **wait for a manager to sign off**, then write the summary. With the SDK alone you can call agent one, then agent two, then hit the wall: PHP cannot wait until Tuesday for the manager to click approve. The request ends and everything the code knew is gone. And if the last step throws, the earlier steps rerun, so you pay for their tokens twice.

What teams build around that wall is always the same pile of glue: a `pending_reviews` table, a couple of jobs, status columns, a resume endpoint, retry logic. Every team reinvents the pile, slightly differently, with slightly different bugs.

This package is that pile, done once, properly, on the substrate Laravel already ships: queues, batches, and retries.

- **A process that can wait.** `awaitHuman()` parks a run in the database for hours or weeks; `resume()` validates the human's answer and continues. `awaitEvent()` does the same for webhooks and other systems.
- **Retry the step that broke, not the whole thing.** Every step's result is committed before the next step runs. If step 5 fails, `$run->retry()` re-runs step 5. Steps 1 to 4 keep their results and their token bill stays paid.
- **Memory between steps.** A state bag is saved after every step, so step 5 can read what step 2 produced, even days later on a different server.
- **A paper trail.** Every run and every attempt of every step is a queryable Eloquent record: status, timings, token counts, errors, who approved what and when.

Already know the multi-agent space? This package deliberately adopts the vocabulary of the five patterns from [Laravel's official multi-agent guidance](https://laravel.com/blog/building-multi-agent-workflows-with-the-laravel-ai-sdk) and makes each one crash-safe: see [The five patterns, made durable](docs/five-patterns-made-durable.md).

> **Status: pre-release.** The core engine (sequential, conditional, parallel, evaluator steps; checkpoint/retry; interrupts; agent handoffs; events; testing fakes) is implemented and tested. APIs may change before 1.0.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `laravel/ai` ^0.10

Want to see it run first? The **[demo app](https://github.com/timmcleod/agent-workflows-demo)** is a complete contract-review pipeline — agents, a simulated outage for the retry demo, a risk-based branch, and human sign-off — driven from four artisan commands.

## Installation

```bash
composer require timmcleod/agent-workflows

php artisan vendor:publish --tag=agent-workflows-config
php artisan migrate
```

## Quick start: your first workflow, end to end

Let's build a small real feature: when a support ticket comes in, an agent drafts a reply, a human reviews it, and only then does the app send it. Three steps, one of which is "wait for a person," which is exactly what plain PHP can't do.

### 1. Write the agent (a normal laravel/ai agent)

Steps that talk to the AI are ordinary SDK agent classes — nothing package-specific on them. The agent owns *how it behaves*; the workflow will decide *what to ask it* (step 3):

```php
// app/Agents/DraftReplyAgent.php

namespace App\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class DraftReplyAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Draft a friendly, concise support reply.';
    }
}
```

### 2. Write the plain-PHP step

Steps that don't need an AI are just invokable classes. They receive the state bag, do their work, and return it:

```php
// app/Workflows/SendReply.php

namespace App\Workflows;

use App\Models\Ticket;
use TimMcLeod\AgentWorkflows\WorkflowState;

class SendReply
{
    public function __invoke(WorkflowState $state): WorkflowState
    {
        $ticket = Ticket::findOrFail($state->get('ticket_id'));

        // The agent's draft was checkpointed under its step id;
        // the reviewer's edits arrive via resume() (step 5 below).
        $ticket->sendReply($state->get('final_reply') ?? $state->get('steps.DraftReplyAgent.text'));

        return $state->set('sent', true);
    }
}
```

### 3. Define the workflow

Every workflow is a class. Generate one:

```bash
php artisan make:agent-workflow TicketReply
```

…and describe the steps in `build()`:

```php
// app/AgentWorkflows/TicketReply.php

namespace App\AgentWorkflows;

use App\Agents\DraftReplyAgent;
use App\Workflows\SendReply;
use TimMcLeod\AgentWorkflows\Workflow;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

class TicketReply extends Workflow
{
    public function build(WorkflowDefinition $workflow): WorkflowDefinition
    {
        return $workflow
            ->step(DraftReplyAgent::class, prompt: $this->draftPrompt(...))
            ->awaitHuman(reason: 'Review the drafted reply', schema: ['final_reply' => 'required|string'])
            ->step(SendReply::class);
    }

    protected function draftPrompt(WorkflowState $state): string
    {
        return 'Draft a reply to this ticket: '.$state->get('ticket_message');
    }
}
```

`build()` stays a skimmable table of contents; each agent's prompt is a named method receiving the workflow state, so the same agent can be asked different things in different workflows (an inline `fn ($state) => ...` or a plain string works too — see [Agent steps](#agent-steps)).

Then list the class in `config/agent-workflows.php` — this is how **queue workers** learn the definition (a worker picking up step 2 must be able to look up what step 3 is, so definitions are registered at boot on every process):

```php
'workflows' => [
    App\AgentWorkflows\TicketReply::class,
],
```

### 4. Start a run from a controller

```php
// routes/web.php (or a controller)

Route::post('/tickets/{ticket}/draft-reply', function (Ticket $ticket, Request $request) {
    $run = AgentWorkflow::start(TicketReply::class, input: [
        'ticket_id' => $ticket->id,
        'ticket_message' => $ticket->message,
    ], participant: $request->user());

    return ['run_id' => $run->id, 'status' => $run->status];
});
```

The response comes back instantly with `status: pending`. **Nothing has executed yet**, and nothing will until a queue worker runs:

```bash
php artisan queue:work
```

The worker picks up step 1 as a job, the agent drafts the reply, the checkpoint is saved, and the run parks itself at the `awaitHuman` step with status `awaiting_human`. It will sit there through deploys, restarts, and weekends.

### 5. Show it to the human, then resume

Your review UI reads the run and shows the draft:

```php
Route::get('/runs/{run}', function (WorkflowRun $run) {
    return [
        'status' => $run->status,                              // "awaiting_human"
        'draft' => $run->state['steps']['DraftReplyAgent']['text'] ?? null,
        'waiting_for' => $run->interrupts()->whereNull('resolved_at')->value('reason'),
    ];
});

Route::post('/runs/{run}/approve', function (WorkflowRun $run, Request $request) {
    $run = $run->resume([
        'final_reply' => $request->input('final_reply'),       // validated against the schema
    ], by: $request->user());

    return ['status' => $run->status];
});
```

`resume()` validates the payload against the schema from step 3, merges it into state, and queues the next step. The worker runs `SendReply`, and the run completes.

### 6. When something breaks

Suppose the mail provider was down and `SendReply` threw. The run is now `failed`, with the draft and the reviewer's edits safely checkpointed:

```php
$run->failed_step;      // "SendReply"
$run->failure_reason;   // the exception message
$run->retry();          // re-queues SendReply only; the agent never re-runs, no tokens re-billed
```

That's the whole loop: agents and plain classes as steps, one `Workflow` class listed in config, `start()` from anywhere, a queue worker doing the work, `resume()` when humans answer, `retry()` when things break. Everything below is detail and more step types.

## Workflow state

The `WorkflowState` bag you saw flowing through the quick start is the package's backbone: it is checkpointed to the database after **every** step, and it's how steps that run minutes or days apart share data. `get()`/`set()`/`has()`/`forget()` accept dot notation; `merge()` and `all()` work on the whole bag. Everything stored must be JSON-serializable.

Three conventions worth knowing:

- Your `start()` input is the initial state.
- Agent output lands under `steps.{step id}` (`steps.DraftReplyAgent.text`, and `.structured` for schema output).
- `resume()` and `deliverEvent()` payloads merge into the top level.

## Method reference

The API has three layers: **`Workflow` classes** describe processes, the **builder** (inside `build()`) describes their steps, and the **run model** is how you act on one execution later. In short: the class describes, `start()` executes, everything on the builder shapes *what will happen*, and everything on the run shapes *what happens next for that one run*.

### The facade — creating runs

| Method | What it does | When to use it |
| --- | --- | --- |
| `AgentWorkflow::register($workflow)` | Registers a `Workflow` class so runs can be started and workers can execute steps. The config `workflows` array calls this for you at boot. | Rarely called directly — list classes in config instead. Direct calls are for runtime registration (tests, packages). |
| `AgentWorkflow::start($name, input: [], participant: null)` | Creates a run: persists a `WorkflowRun` row with `input` as its initial state and dispatches the first step onto the queue. Returns the run immediately — steps execute in workers. | Anywhere something should *happen*: a controller, a command, a listener. Accepts a registered name or a `Workflow` class name. Pass `participant:` to associate the run with a user/model. |
| `AgentWorkflow::fake()` | Swaps in a recording manager for tests. Workflows still execute; you get `assertStarted()`, `assertStepRan()`, `assertCompleted()`, etc. | Feature tests. See [Testing](#testing). |
| `AgentWorkflow::resolveAgentFor(...)` / `transferConversation(...)` | Conversation routing after handoffs. | See [Handoffs](#handoffs) — unrelated to runs; these route *conversations*. |

### The builder — describing steps

All builder methods append a step and return the builder, so they chain. Every step type checkpoints state when it completes. Each accepts `as:` to override the step's auto-generated id (the class basename, or e.g. `when:2`) — useful when the same class appears twice or you want stable ids for auditing.

| Method | What it does | When to use it |
| --- | --- | --- |
| `step($target, prompt:)` | Adds a step that runs a unit of work, in the order added. Each runs after the previous step's state is committed. For agent steps, `prompt:` (closure over state, or string) is what the agent gets asked. | The default. `$target` is an SDK agent class (agent step) or any invokable class (callback step) — the builder tells them apart. If in doubt, it's `step()`. |
| `when($condition, then:, else:)` | Evaluates the closure against checkpointed state at runtime and runs one branch (or skips ahead if `else:` is omitted and the condition is false). Continues sequentially afterward; records the decision in `steps.{id}.branch`. | A fork in the road *within* a run: escalate vs auto-approve, premium vs standard handling. For routing whole conversations between agents across turns, use [handoffs](#handoffs) instead. |
| `parallel([$a, $b], merge:, mode:)` | Fans out into concurrent branches from one state snapshot, then merges and continues. Default `mode: 'batch'` is a queued `Bus::batch`; `mode: 'sync'` runs in-process. | Independent work whose results you need together (analyze financials + legal + news, then synthesize). Not for steps that depend on each other — that's a chain. Provide `merge:` when branches may write the same keys. |
| `evaluate($target, until:, maxIterations:)` | Runs `$target` repeatedly until the predicate on state passes or the cap is hit, checkpointing each iteration. Records `iteration` and `satisfied` under `steps.{id}`. | Generate-critique-revise loops where quality is judged by a predicate (usually over a critic agent's structured output). Always set a realistic `maxIterations` — it's your token budget. |
| `awaitHuman(reason:, schema:)` | Parks the run as `awaiting_human`, persisting the reason and validation rules for the expected response. Nothing runs until `resume()`. | Sign-offs, edits, any decision a person must make. The `schema:` is what your approval UI should collect. |
| `awaitEvent($event)` | Parks the run as `awaiting_event` until `deliverEvent()` is called with the matching name. | Waiting on *systems* rather than people: a webhook, a payment confirmation, another job finishing. |

### The run — acting on one execution

`AgentWorkflow::start()` returns a `TimMcLeod\AgentWorkflows\Models\WorkflowRun` (an ordinary Eloquent model you can also query later).

| Method | What it does | When to use it |
| --- | --- | --- |
| `$run->retry()` | Re-dispatches a **failed** run from its failed step. Earlier steps keep their committed results. | After you've fixed whatever failed (provider outage, bad config, a bug). Throws unless the run's status is `failed`. |
| `$run->resume($response, by:)` | Wakes a run parked by `awaitHuman()` (validates against the schema, merges into state) or by a tool-approval pause (replays the decisions map into the agent). | When the human answers. `by:` records who, on the interrupt. |
| `$run->deliverEvent($event, $payload)` | Wakes a run parked by `awaitEvent()`; the payload merges into state. | From the webhook/listener where the awaited thing happens. |
| `$run->cancel()` | Ends the run as `cancelled`, resolving any open interrupt. A step already executing finishes but cannot advance a cancelled run — its result is discarded at the boundary. | Abandoning a run from any non-terminal state (including `failed`). |
| `$run->workflowState()` | The current checkpoint as a `WorkflowState` bag. | Reading run data in UIs or listeners (`$run->state` gives the raw array). |

## The five patterns, made durable

> The full version of this section — each official blog example rewritten side by side with its durable equivalent — lives in [docs/five-patterns-made-durable.md](docs/five-patterns-made-durable.md).

### 1. Prompt chaining — `step()`

```php
// In ContentPipeline::build():
return $workflow
    ->step(OutlineAgent::class)
    ->step(DraftAgent::class)
    ->step(PolishAgent::class);
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
// In SupportTriage::build():
return $workflow
    ->step(ClassifyTicketAgent::class)
    ->when(fn (WorkflowState $s) => $s->get('steps.ClassifyTicketAgent.structured.urgent'),
        then: EscalationAgent::class,
        else: AutoReplyAgent::class)
    ->step(LogResolution::class);
```

Omit `else:` to simply skip ahead when the condition is false. The decision is recorded in state under `steps.{id}.branch` for auditing.

### 3. Parallelization — `parallel()`

Fan out into concurrent branches, each starting from the same state snapshot, then merge and continue:

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

By default branches run as a **`Bus::batch`** — durable and distributed across your queue workers. Pass `mode: 'sync'` to run them in-process via `Concurrency::run()` for request-lifetime fan-outs.

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
// In AdCopy::build():
return $workflow
    ->step(BriefAgent::class)
    ->evaluate(ReviseCopyAgent::class, as: 'revise',
        prompt: fn (WorkflowState $s) => 'Improve this copy and score your result 1-10: '
            .($s->get('steps.revise.text') ?? $s->get('steps.BriefAgent.text')),
        until: fn (WorkflowState $s) => $s->get('steps.revise.structured.score', 0) >= 8,
        maxIterations: 5)
    ->step(PublishCopy::class);
```

The loop target both revises and scores (a structured response with `copy` and `score`), and the predicate reads the loop's own output under its step id. After the loop, `steps.{id}.iteration` holds the count and `steps.{id}.satisfied` records whether the predicate passed or the cap was hit.

## Human-in-the-loop

### Pause for a human — `awaitHuman()`

Park a run until someone signs off. The interrupt persists a reason and an optional response schema (Laravel validation rules), so your approval UI knows exactly what to collect:

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

The run parks as `awaiting_human` — for minutes or for weeks, across deploys and queue restarts. Resume it whenever the human responds:

```php
$run->resume(['approved' => true, 'notes' => 'LGTM'], by: $request->user());
```

The payload is validated against the schema (a `ValidationException` leaves the run parked), merged into state for the steps that follow, and the resolution — payload, who resolved it, when — is recorded on the interrupt for audit.

### Wait for an application event — `awaitEvent()`

Park a run until something happens elsewhere in your system:

```php
// In OrderFlow::build():
return $workflow
    ->step(PrepareOrderAgent::class)
    ->awaitEvent('payment.confirmed')
    ->step(FulfillmentAgent::class);
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

Any `laravel/ai` agent class can be a step — agents stay plain SDK classes with no package-specific code on them. The step's prompt is defined where the step is, so the same agent can be asked different things in different workflows:

```php
// In ContractReview::build():
return $workflow
    ->step(ExtractClausesAgent::class,
        prompt: fn (WorkflowState $s) => 'Extract the key clauses: '.$s->get('document.text'))
    ->step(RiskAnalysisAgent::class,
        prompt: fn (WorkflowState $s) => 'Assess the risk of: '.$s->get('steps.ExtractClausesAgent.text'));
```

`prompt:` takes any closure receiving the state — an inline `fn` as above, or a first-class callable to a method on your workflow class (`prompt: $this->riskPrompt(...)`), which keeps `build()` skimmable when prompts run long. A plain string works for static prompts. If a step has no `prompt:`, the state's `prompt` key is used — handy for chat-shaped runs where the input *is* the prompt.

Agent targets in other step types carry prompts too: `when(..., thenPrompt:, elsePrompt:)` for branches, and `evaluate(..., prompt:)` for the loop body.

After the step runs, its output is checkpointed under `steps.{step id}`:

```php
$run->state['steps']['RiskAnalysisAgent']['text'];        // the response text
$run->state['steps']['RiskAnalysisAgent']['structured'];  // structured output, if the agent declares a schema
```

Token usage from every agent response is recorded on the step's audit row.

### Agents use their tools freely within a step

An agent step is one full agentic turn, not one LLM call. If the agent has tools, the SDK's tool loop runs to completion inside the step: the model calls a tool, reads the result, thinks, calls another, and keeps going until it has an answer (capped by `#[MaxSteps]` on the agent). The workflow checkpoints the finished result. So a single `->step(ResearchAgent::class)` can look up an order, query a knowledge base, and draft a response, all in one step.

Three things follow from "the loop lives inside one queued job":

- **Give workers room.** A tool-heavy agent makes several LLM calls in one job, so run workers with a `--timeout` (and matching `retry_after`) that covers the whole turn, not one call.
- **The step is the retry unit.** A failure at tool-round 3 retries the whole step from round 1. There is no mid-loop checkpoint, so keep tools idempotent, or pull side-effecting work into its own callback step after the agent.
- **Approval pauses are the exception.** A tool that [requires approval](#sdk-tool-approvals-become-workflow-interrupts) mid-loop parks the run; on `resume()` the loop continues from where it paused, not from round 1.

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
use TimMcLeod\AgentWorkflows\Events\WorkflowFailed;

Event::listen(WorkflowFailed::class, function (WorkflowFailed $event) {
    Notification::send($ops, new WorkflowFailedNotification($event->run, $event->exception));
});
```

The full set, all under `TimMcLeod\AgentWorkflows\Events`:

- `WorkflowStarted($run)` — a run was created.
- `StepCompleted($run, $step)` — fires per step, including parallel branches, and carries the audit row (token usage included), which makes cost accounting a one-listener job.
- `WorkflowInterrupted($run, $interrupt)` — the run parked: `awaitHuman()`, `awaitEvent()`, or an agent tool-approval pause. The interrupt carries the reason, response schema, and context.
- `WorkflowResumed($run, $interrupt)` — a human resumed it or the awaited event arrived.
- `WorkflowCompleted($run)` / `WorkflowFailed($run, $exception)` / `WorkflowCancelled($run)` — terminal transitions.

## Workflow classes

Every workflow is a class extending `Workflow` with a `build()` method (see the [quick start](#quick-start-your-first-workflow-end-to-end) for the full flow: `php artisan make:agent-workflow`, then list the class in the config `workflows` array).

Two details worth knowing:

- A workflow registers under the kebab-cased class name (`ContractReview` → `contract-review`); override `name()` to choose your own. Runs store this name, so treat it as stable once runs exist.
- `AgentWorkflow::start()` accepts the class name (`ContractReview::class` — type-safe, refactor-friendly) or the registered string name; both reach the same definition.

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

## Operations

What to know once workflows run in production:

- **Isolate the queue.** Point `agent-workflows.queue.connection`/`queue.queue` at a dedicated queue so long agent turns don't starve your app's other jobs, and give it its own worker process.
- **Give workers room.** An agent step is one full agentic turn — several LLM calls when tools are involved. Run its workers with a `--timeout` (and matching `retry_after`) sized to your slowest step, not one HTTP call.
- **Schedule the sweeper.** Workers die ungracefully (OOM, SIGKILL, deploys); the sweeper recovers runs stranded that way, re-dispatching from the checkpoint (or marking them failed, per `sweep.action`):

  ```php
  Schedule::command('agent-workflows:sweep')->everyFiveMinutes();
  ```

  Set `sweep.stale_after` comfortably above your longest step — including parallel fan-outs — so genuinely busy runs are never swept. Re-dispatch is safe: duplicate deliveries are rejected by the engine's step claims.
- **Execution semantics.** Step bodies are at-least-once (a crash after a side effect but before the checkpoint re-runs the body on recovery); checkpoints and cursor advances are exactly-once. Keep step side effects idempotent, or isolate them in their own step.

## Configuration

`config/agent-workflows.php`:

| Key                                                  | What it does                                                                 |
| ---------------------------------------------------- | ---------------------------------------------------------------------------- |
| `workflows`                                          | Your Workflow classes, registered at boot (workers included).               |
| `queue.connection` / `queue.queue`                   | Route step jobs onto their own connection/queue.                            |
| `tables.*`                                           | Rename the package's tables (runs, steps, interrupts, conversation owners). |
| `sweep.stale_after` / `sweep.action`                 | Staleness threshold (seconds) and recovery action for the sweeper.          |
| `definition_drift`                                   | `strict` (refuse to resume a changed definition) or `loose` (by step name). |

## What this package is not

- **Not an arbitrary graph engine.** Sequential + conditional + parallel + loop + interrupt covers the overwhelming majority of production workflows. No cycles-with-reducers, no time-travel debugging.
- **Not a group-chat / free-form agent-debate framework.** The SDK's orchestrator-workers (sub-agents as tools) plus handoffs cover the useful cases.
- **Not a fork or patch of `laravel/ai`.** It composes the SDK's public API only, behind a single adapter seam.

## License

MIT
