# Human-in-the-loop

Durable runs can stop and wait — for a person, for another system, or for an SDK tool approval — and pick up exactly where they left off. This page covers all three gates and the security posture for the payloads that wake them.

## Pause for a human — `awaitHuman()`

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

Real processes have SLAs — a run shouldn't wait forever. Give the gate a `timeout` and the [scheduled sweeper](operations.md) acts on runs still waiting when it expires:

```php
->awaitHuman(reason: 'Final sign-off required', schema: [
    'approved' => 'required|boolean',
    'notes' => 'nullable|string',
], timeout: CarbonInterval::days(3), timeoutResponse: [
    'approved' => false,
    'notes' => 'Auto-rejected: sign-off timed out.',
])
```

`timeout:` takes seconds or any `DateInterval` (`new DateInterval('P3D')`, `CarbonInterval::days(3)`). With a `timeoutResponse:`, the run resumes with that payload — an auto-decision, validated against the schema like any human answer. Without one, the run **fails** at the gate; `$run->retry()` re-arms the same wait with a fresh deadline, so "give them another three days" is one call.

## Wait for an application event — `awaitEvent()`

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

Delivering the wrong event name throws; the payload is merged into state. Like `awaitHuman()`, the step takes an optional `schema:` (Laravel validation rules) — the delivered payload is validated against it, and only the declared fields reach state:

```php
->awaitEvent('payment.confirmed', schema: ['amount' => 'required|integer|min:1'])
```

## Treat resume and event payloads as untrusted input

`resume()` and `deliverEvent()` payloads merge into the same state bag your steps, prompts, and conditions read. **Whitelist the fields you accept — never pass raw request input** like `$request->all()`: a caller who controls the payload controls whatever state keys it writes (including the `prompt` key that agent steps fall back to). The engine-owned `steps` key is reserved and rejected outright, and schema-validated payloads are stripped to their declared fields. Authorizing *who* may resume a run or deliver an event is your application's job — put these calls behind your usual auth middleware and policies.

## SDK tool approvals become workflow interrupts

`laravel/ai` tools can [require approval](https://laravel.com/docs/13.x/ai-sdk) before they run. When an agent step pauses on tool approvals, this package converts the pause into a workflow interrupt: the run parks as `awaiting_human` with the pending approvals (tool, arguments, reason) persisted on the interrupt. Resume with a decisions map and the package replays it into the paused conversation:

```php
$run = AgentWorkflow::start('deploy', input: ['prompt' => 'Deploy the app']);

$run->status;                          // awaiting_human
$run->interrupts->last()->context;     // ['approvals' => [['id' => 'toolu_1', 'tool' => 'deploy_tool', ...]]]

$run->resume(['toolu_1' => true]);     // true / false / Decision::edit([...]) per tool call
```

The agent must remember conversations (the SDK requires that to pause); decisions are checkpointed before replay, so a crash mid-resume replays them safely on retry.

