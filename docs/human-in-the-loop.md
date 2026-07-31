# Human in the Loop

- [Introduction](#introduction)
- [Awaiting Human Input](#awaiting-human-input)
  - [Validating Responses](#validating-responses)
  - [Timeouts](#timeouts)
- [Awaiting Application Events](#awaiting-application-events)
- [Payload Validation](#payload-validation)
- [Tool Approvals](#tool-approvals)

## Introduction

Durable runs may stop and wait — for a person, for another system, or for an SDK tool approval — and pick up exactly where they left off. This page covers all three gates and the security posture for the payloads that wake them.

## Awaiting Human Input

The `awaitHuman` method parks a run until someone signs off:

```php
// In ContractReview::build():
return $workflow
    ->step(ExtractClausesAgent::class)
    ->step(RiskAnalysisAgent::class)
    ->awaitHuman(reason: 'Final sign-off required')
    ->step(GenerateSummaryAgent::class);
```

The run parks with a status of `awaiting_human` — for minutes or for weeks, across deploys and queue restarts. You may resume it whenever the human responds; the payload merges into state for the steps that follow:

```php
$run->resume(['approved' => true], by: $request->user());
```

The resolution — payload, who resolved it, when — is recorded on the interrupt for audit.

### Validating Responses

You may give the gate a response schema of Laravel validation rules. The schema is persisted on the interrupt, so your approval UI knows exactly what to collect:

```php
->awaitHuman(reason: 'Final sign-off required', schema: [
    'approved' => 'required|boolean',
    'notes' => 'nullable|string',
])
```

The resume payload is validated against the schema before it merges into state — a `ValidationException` leaves the run parked, and only the declared fields reach state.

### Timeouts

Real processes have SLAs — a run should not wait forever. You may give the gate a `timeout`, and the [scheduled sweeper](operations.md) acts on runs still waiting when it expires:

```php
->awaitHuman(reason: 'Final sign-off required', schema: [
    'approved' => 'required|boolean',
    'notes' => 'nullable|string',
], timeout: CarbonInterval::days(3), timeoutResponse: [
    'approved' => false,
    'notes' => 'Auto-rejected: sign-off timed out.',
])
```

The `timeout` argument accepts seconds or any `DateInterval` (`new DateInterval('P3D')`, `CarbonInterval::days(3)`). When a `timeoutResponse` is provided, the run resumes with that payload — an auto-decision, validated against the schema like any human answer. Without one, the run **fails** at the gate; calling `retry` re-arms the same wait with a fresh deadline, so "give them another three days" is one method call.

## Awaiting Application Events

The `awaitEvent` method parks a run until something happens elsewhere in your system:

```php
// In OrderFlow::build():
return $workflow
    ->step(PrepareOrderAgent::class)
    ->awaitEvent('payment.confirmed')
    ->step(FulfillmentAgent::class);
```

When the awaited thing happens — in a webhook controller, a listener, anywhere — deliver the event to the run:

```php
$run->deliverEvent('payment.confirmed', ['amount' => $payment->amount]);
```

Delivering the wrong event name throws, and the payload is merged into state. Like `awaitHuman`, the step accepts an optional `schema` of Laravel validation rules — the delivered payload is validated against it, and only the declared fields reach state:

```php
->awaitEvent('payment.confirmed', schema: ['amount' => 'required|integer|min:1'])
```

## Payload Validation

`resume` and `deliverEvent` payloads merge into the same state bag your steps, prompts, and conditions read.

> [!WARNING]
> Whitelist the fields you accept — never pass raw request input like `$request->all()`. A caller who controls the payload controls whatever state keys it writes, including the `prompt` key that agent steps fall back to.

The engine-owned `steps` key is reserved and rejected outright, and schema-validated payloads are stripped to their declared fields. Authorizing *who* may resume a run or deliver an event is your application's job — put these calls behind your usual auth middleware and policies.

## Tool Approvals

`laravel/ai` tools may [require approval](https://laravel.com/docs/13.x/ai-sdk) before they run. When an agent step pauses on tool approvals, the package converts the pause into a workflow interrupt: the run parks as `awaiting_human` with the pending approvals (tool, arguments, reason) persisted on the interrupt. Resume the run with a map of decisions, and the package replays them into the paused conversation:

```php
$run = Deploy::start(['prompt' => 'Deploy the app']);

$run->status;                          // awaiting_human
$run->interrupts->last()->context;     // ['approvals' => [['id' => 'toolu_1', 'tool' => 'deploy_tool', ...]]]

$run->resume(['toolu_1' => true]);     // true / false / Decision::edit([...]) per tool call
```

The agent must remember conversations — the SDK requires that to pause. Decisions are checkpointed before replay, so a crash mid-resume replays them safely on retry.
