# Testing

- [Introduction](#introduction)
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

With the default `sync` queue in tests, an entire workflow executes inside the `start` call — no worker is needed.

## Available Assertions

| Assertion | Verifies |
| --- | --- |
| `assertStarted($name, $callback = null)` | A run of the workflow was started, optionally matching the callback. |
| `assertNotStarted($name)` | No run of the workflow was started. |
| `assertNothingStarted()` | No runs were started at all. |
| `assertStepRan($step)` | The step ran, by class name or step id. |
| `assertStepDidNotRun($step)` | The step never ran. |
| `assertStepRanTimes($step, $times)` | The step completed exactly `$times` times — useful for loop and debate rounds. |
| `assertCompleted($name)` | A run of the workflow completed. |
| `assertFailed($name)` | A run of the workflow failed. |
| `assertInterrupted($name, $reason = null)` | A run parked on an interrupt, optionally matching the reason. |
| `assertResumed($name)` | A parked run was resumed. |
| `assertCancelled($name)` | A run was cancelled. |
