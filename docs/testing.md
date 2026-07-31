# Testing workflows

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

