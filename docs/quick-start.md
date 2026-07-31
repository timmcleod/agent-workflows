# Quick start: your first workflow, end to end

Let's build a small real feature: when a support ticket comes in, an agent drafts a reply, a human reviews it, and only then does the app send it. Three steps, one of which is "wait for a person," which is exactly what plain PHP can't do.

## 1. Write the agent (a normal laravel/ai agent)

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

## 2. Write the plain-PHP step

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

## 3. Define the workflow

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

`build()` stays a skimmable table of contents; each agent's prompt is a named method receiving the workflow state, so the same agent can be asked different things in different workflows (an inline `fn ($state) => ...` or a plain string works too — see [Agent steps](agent-steps.md)).

Then list the class in `config/agent-workflows.php` — this is how **queue workers** learn the definition (a worker picking up step 2 must be able to look up what step 3 is, so definitions are registered at boot on every process):

```php
'workflows' => [
    App\AgentWorkflows\TicketReply::class,
],
```

## 4. Start a run from a controller

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

## 5. Show it to the human, then resume

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

## 6. When something breaks

Suppose the mail provider was down and `SendReply` threw. The run is now `failed`, with the draft and the reviewer's edits safely checkpointed:

```php
$run->failed_step;      // "SendReply"
$run->failure_reason;   // the exception message
$run->retry();          // re-queues SendReply only; the agent never re-runs, no tokens re-billed
```

That's the whole loop: agents and plain classes as steps, one `Workflow` class listed in config, `start()` from anywhere, a queue worker doing the work, `resume()` when humans answer, `retry()` when things break. The [docs index](README.md) covers the other step types and everything around them.

## Workflow classes: three details worth knowing

Every workflow is a class extending `Workflow` with a `build()` method, generated by `php artisan make:agent-workflow` and listed in the config `workflows` array — the flow above.

- A workflow registers under the kebab-cased class name (`ContractReview` → `contract-review`); override `name()` to choose your own. Runs store this name, so treat it as stable once runs exist.
- `AgentWorkflow::start()` accepts the class name (`ContractReview::class` — type-safe, refactor-friendly) or the registered string name; both reach the same definition.
- Override `stateClass()` to hydrate a [typed state class](typed-state.md) for every step of this workflow.

## Deploys and definition drift

Every run stores a hash of its definition at start time. If a deploy changes the workflow while a run is in flight, resuming it is refused by default (`definition_drift: strict`) so a run never executes against steps it never agreed to. Set `loose` to resume best-effort by step name.
