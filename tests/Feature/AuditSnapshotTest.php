<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\BranchAStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\BranchBStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

it('stores full state snapshots on audit rows by default', function () {
    SummarizeAgent::fake(['A summary.']);

    defineWorkflow('audit-full', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(SummarizeAgent::class, prompt: 'Summarize.'));

    $run = AgentWorkflow::start('audit-full', []);

    $agentRow = $run->steps()->where('step_id', 'SummarizeAgent')->sole();

    // The agent row's snapshot includes the earlier step's keys.
    expect($agentRow->output_state)->toHaveKey('prepared')
        ->and($agentRow->output_state['steps']['SummarizeAgent']['text'])->toBe('A summary.');
});

it('stores only the step-owned subtree when audit.step_output is minimal', function () {
    config()->set('agent-workflows.audit.step_output', 'minimal');

    SummarizeAgent::fake(['A summary.']);

    defineWorkflow('audit-minimal', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(SummarizeAgent::class, prompt: 'Summarize.')
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('audit-minimal', []);

    expect($run->status)->toBe(RunStatus::Completed);

    $agentRow = $run->steps()->where('step_id', 'SummarizeAgent')->sole();

    // Only the step's own checkpoint, not the accumulated state.
    expect($agentRow->output_state)->toBe(['steps' => ['SummarizeAgent' => ['text' => 'A summary.']]])
        ->and($agentRow->output_state)->not->toHaveKey('prepared');

    // The run's own checkpoint is untouched by the audit policy.
    expect($run->state['steps']['SummarizeAgent']['text'])->toBe('A summary.')
        ->and($run->state['finalized'])->toBeTrue();
});

it('keeps full snapshots on parallel branch rows regardless of the audit policy', function () {
    config()->set('agent-workflows.audit.step_output', 'minimal');

    defineWorkflow('audit-branches', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([BranchAStep::class, BranchBStep::class])
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('audit-branches', []);

    // The merge consumed the branch rows' full snapshots correctly.
    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['a'])->toBe('from-a')
        ->and($run->state['b'])->toBe('from-b');

    $branchRow = $run->steps()->where('step_id', 'BranchAStep')->where('status', StepStatus::Completed->value)->sole();

    expect($branchRow->output_state)->toHaveKey('a')
        ->and($branchRow->output_state)->toHaveKey('prepared');
});
