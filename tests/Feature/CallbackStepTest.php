<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\ArrayReturningStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\DefinitionAwareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\UsageReportingStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

it('passes the step definition to callback handlers that ask for it', function () {
    defineWorkflow('introspective', fn (WorkflowDefinition $workflow) => $workflow
        ->step(DefinitionAwareStep::class, as: 'well-named'));

    $run = AgentWorkflow::start('introspective', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['seen_step_id'])->toBe('well-named');
});

it('accepts a StepResult return and records its usage on the audit row', function () {
    defineWorkflow('usage-reporting', fn (WorkflowDefinition $workflow) => $workflow
        ->step(UsageReportingStep::class));

    $run = AgentWorkflow::start('usage-reporting', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['worked'])->toBeTrue();

    expect($run->steps()->sole()->usage)
        ->toBe(['prompt_tokens' => 5, 'completion_tokens' => 7]);
});

it('still rejects returns that are not WorkflowState, StepResult, or null', function () {
    defineWorkflow('still-bad-return', fn (WorkflowDefinition $workflow) => $workflow
        ->step(ArrayReturningStep::class));

    try {
        AgentWorkflow::start('still-bad-return', []);
        $this->fail('Expected a WorkflowException.');
    } catch (WorkflowException $e) {
        expect($e->getMessage())->toContain('returned [array]')
            ->and($e->getMessage())->toContain('StepResult');
    }
});
