# Testing

- [Introduction](#introduction)
- [Faking Agents](#faking-agents)
- [Testing Gates](#testing-gates)
- [Testing Debates](#testing-debates)
- [Available Assertions](#available-assertions)

## Introduction

The `AgentWorkflow::fake` method records workflow lifecycle events for assertions. Workflows still execute, so you should fake the agents themselves using the SDK's `Agent::fake` method:

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

With the default `sync` queue in tests, an entire workflow executes inside the `start` call. No worker is needed. This includes `parallel` steps: on the sync queue, branches run in-process with the `sync` concurrency driver, so they share the test database and `Agent::fake` state without any concurrency configuration on your part (see the `parallel.sync_driver` config key to opt back into process isolation).

## Faking Agents

A fake's responses are consumed sequentially, one per prompt. Plain strings fake text agents, and arrays fake structured output. A closure receives the prompt the agent was actually asked, which is the way to assert on [prompt resolution](agent-steps.md#prompts):

```php
SummarizeAgent::fake(['First response.', 'Second response.']);   // text, per call
RiskAnalysisAgent::fake([['riskScore' => 9]]);                   // structured
DraftAgent::fake([fn (string $prompt) => "Echo: {$prompt}"]);    // inspect the prompt
```

## Testing Gates

A run that hits `awaitHuman` parks inside the `start` call. You may resume it in the test the way your application would:

```php
$fake = AgentWorkflow::fake();
DraftReplyAgent::fake(['A drafted reply.']);

$run = TicketReply::start(['ticket_message' => 'Where is my order?']);

$fake->assertInterrupted('ticket-reply', 'Review the drafted reply');
expect($run->status)->toBe(RunStatus::AwaitingHuman);

$run = $run->resume(['final_reply' => 'It ships Monday.']);

$fake->assertResumed('ticket-reply');
expect($run->status)->toBe(RunStatus::Completed);
```

`awaitEvent` gates test the same way through `$run->deliverEvent(...)`.

## Testing Debates

One array entry per round scripts a whole [debate](agent-debate.md). Rounds are step completions:

```php
$fake = AgentWorkflow::fake();

BullCaseAgent::fake(['Opening bull case.', 'Rebuttal.']);
BearCaseAgent::fake(['Opening bear case.', 'Concession.']);
VerdictAgent::fake([
    ['consensus' => false, 'summary' => 'Still apart.'],
    ['consensus' => true,  'summary' => 'Agreed: proceed.'],
]);

$run = AcquisitionReview::start(['filings' => '...']);

expect($run->state['steps']['thesis']['satisfied'])->toBeTrue();

$fake->assertStepRanTimes('thesis', 2);
```

## Available Assertions

| Assertion | Verifies |
| --- | --- |
| `assertStarted($name, $callback = null)` | A run of the workflow was started, optionally matching the callback. |
| `assertNotStarted($name)` | No run of the workflow was started. |
| `assertNothingStarted()` | No runs were started at all. |
| `assertStepRan($step)` | The step ran, by class name or step id. |
| `assertStepDidNotRun($step)` | The step never ran. |
| `assertStepRanTimes($step, $times)` | The step completed exactly `$times` times, useful for loop and debate rounds. |
| `assertCompleted($name)` | A run of the workflow completed. |
| `assertFailed($name)` | A run of the workflow failed. |
| `assertInterrupted($name, $reason = null)` | A run parked on an interrupt, optionally matching the reason. |
| `assertResumed($name)` | A parked run was resumed. |
| `assertCancelled($name)` | A run was cancelled. |
