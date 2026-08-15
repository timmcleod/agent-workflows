# Quick Start

- [Introduction](#introduction)
- [Installation](#installation)
- [Creating an Agent](#creating-an-agent)
- [Creating a Callback Step](#creating-a-callback-step)
- [Defining the Workflow](#defining-the-workflow)
- [Registering the Workflow](#registering-the-workflow)
- [Starting Runs](#starting-runs)
- [Resuming Runs](#resuming-runs)
- [Handling Failures](#handling-failures)
- [Workflow Classes](#workflow-classes)

## Introduction

This guide builds a small, real feature end to end: when a support ticket arrives, an agent drafts a reply, a human reviews the draft, and only then does the application send it. Three steps — one of which is "wait for a person", which is exactly what a plain request cycle cannot do.

## Installation

The package requires PHP 8.3+, Laravel 12 or 13, and `laravel/ai` ^0.10.3. Install it via Composer, then publish the configuration file and run the migrations:

```bash
composer require timmcleod/agent-workflows

php artisan vendor:publish --tag=agent-workflows-config
php artisan migrate
```

## Creating an Agent

Steps that talk to the AI are ordinary `laravel/ai` agent classes — nothing package-specific is required. The agent defines *how it behaves*; the workflow will decide *what to ask it*:

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

## Creating a Callback Step

Steps that do not need an AI are plain invokable classes. They receive the workflow's state, do their work, and return the state:

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
        // the reviewer's edits arrive via resume().
        $ticket->sendReply($state->get('final_reply') ?? $state->get('steps.DraftReplyAgent.text'));

        return $state->set('sent', true);
    }
}
```

## Defining the Workflow

Every workflow is a class. You may generate one using the `make:agent-workflow` Artisan command:

```bash
php artisan make:agent-workflow TicketReply
```

Within the generated class, describe the workflow's steps in the `build` method:

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
            ->step(DraftReplyAgent::class, 'Draft a friendly, concise reply to the customer ticket.')
            ->awaitHuman(reason: 'Review the drafted reply', schema: ['final_reply' => 'required|string'])
            ->step(SendReply::class);
    }
}
```

The prompt is the step's second argument. A plain string is the simplest form; to thread run input or an earlier step's output into the prompt, pass a closure receiving the workflow state instead: `fn (WorkflowState $state) => 'Draft a reply to: '.$state->get('ticket_message')`. When prompts grow long, they can also live as named methods on the workflow class, bound by convention; see [agent steps](agent-steps.md#prompts) for all the forms.

## Registering the Workflow

Next, list the class in the `workflows` array of your `config/agent-workflows.php` configuration file:

```php
'workflows' => [
    App\AgentWorkflows\TicketReply::class,
],
```

Definitions are registered at boot on every process because **queue workers** need them too — a worker picking up step 2 must be able to look up what step 3 is.

## Starting Runs

You may start a run from anywhere in your application — a controller, a job, an Artisan command — using the workflow's static `start` method:

```php
// routes/web.php (or a controller)

Route::post('/tickets/{ticket}/draft-reply', function (Ticket $ticket, Request $request) {
    $run = TicketReply::start([
        'ticket_id' => $ticket->id,
        'ticket_message' => $ticket->message,
    ], participant: $request->user());

    return ['run_id' => $run->id, 'status' => $run->status];
});
```

The response returns instantly with a status of `pending`. Nothing has executed yet, and nothing will until a queue worker runs:

```bash
php artisan queue:work
```

The worker picks up step 1 as a job, the agent drafts the reply, the checkpoint is saved, and the run parks itself at the `awaitHuman` step with a status of `awaiting_human`. It will sit there through deploys, restarts, and weekends.

## Resuming Runs

Your review UI reads the run and shows the draft:

```php
Route::get('/runs/{run}', function (WorkflowRun $run) {
    return [
        'status' => $run->status,                              // "awaiting_human"
        'draft' => $run->state['steps']['DraftReplyAgent']['text'] ?? null,
        'waiting_for' => $run->interrupts()->whereNull('resolved_at')->value('reason'),
    ];
});
```

When the human responds, resume the run with their answer:

```php
Route::post('/runs/{run}/approve', function (WorkflowRun $run, Request $request) {
    $run = $run->resume([
        'final_reply' => $request->input('final_reply'),       // validated against the schema
    ], by: $request->user());

    return ['status' => $run->status];
});
```

The `resume` method validates the payload against the schema from the `awaitHuman` step, merges it into state, and queues the next step. The worker runs `SendReply`, and the run completes.

## Handling Failures

Suppose the mail provider was down and `SendReply` threw. The run is now `failed`, with the draft and the reviewer's edits safely checkpointed:

```php
$run->failed_step;      // "SendReply"
$run->failure_reason;   // the exception message
$run->retry();          // re-queues SendReply only
```

The `retry` method re-runs only the failed step — the agent never re-runs, and no tokens are re-billed.

That's the whole loop: agents and plain classes as steps, one `Workflow` class listed in config, `start` from anywhere, a queue worker doing the work, `resume` when humans answer, and `retry` when things break. The [documentation index](README.md) covers the other step types and everything around them.

## Workflow Classes

Every workflow is a class extending `Workflow` with a `build` method, generated by `php artisan make:agent-workflow` and listed in the config `workflows` array — [Defining Workflows](defining-workflows.md) documents the full definition API, including conditions, parallel fan-outs, and loops. A few details worth knowing:

- A workflow registers under the kebab-cased class name (`ContractReview` → `contract-review`). You may override the `name` method to choose your own. Runs store this name, so you should treat it as stable once runs exist.
- You may start a run using the workflow's static `start` method as shown above, or via the facade — `AgentWorkflow::start` accepts the class name or the registered string name; all three reach the same definition.
- You may override the `stateClass` method to hydrate a [typed state class](workflow-state.md) for every step of the workflow.
- You may pass a singleton `key` to `start` to enforce one active run per business entity (`key: "ticket:{$ticket->id}"`), and a `group` to act once when a set of related runs finishes — see [runs & observability](runs-and-observability.md#singleton-keys).
- Every run stores a hash of its definition at start time, so a deploy that changes a workflow refuses to resume its in-flight runs by default — see [definition drift](defining-workflows.md#definition-drift).
