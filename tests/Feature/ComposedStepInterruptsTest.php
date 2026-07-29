<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\AgentResponse;
use TimMcLeod\AgentWorkflows\Enums\InterruptType;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\DeployAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

it('parks the run when an evaluate body pauses on tool approvals, then resumes the loop', function () {
    DeployAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-1', 'deploy_tool', ['env' => 'production'], 'Needs approval'),
        ]),
        'Deployed successfully.',
    ]);

    defineWorkflow('deploy-loop', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->evaluate(DeployAgent::class, as: 'deploy',
            prompt: 'Deploy the app.',
            until: fn (WorkflowState $s) => $s->get('steps.deploy.text') === 'Deployed successfully.',
            maxIterations: 3)
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('deploy-loop', []);

    // The pause surfaced as a workflow interrupt at the evaluate step,
    // and no iteration was recorded for the half-finished turn.
    expect($run->status)->toBe(RunStatus::AwaitingHuman)
        ->and($run->current_step)->toBe('deploy')
        ->and($run->interrupts()->sole()->type)->toBe(InterruptType::Approval)
        ->and($run->state['steps']['deploy'] ?? [])->not->toHaveKey('iteration');

    $run = $run->resume(['call-1' => true]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps']['deploy']['text'])->toBe('Deployed successfully.')
        ->and($run->state['steps']['deploy']['iteration'])->toBe(1)
        ->and($run->state['steps']['deploy']['satisfied'])->toBeTrue()
        ->and($run->state['finalized'])->toBeTrue();
});

it('fails the run when a parallel branch pauses on tool approvals', function () {
    DeployAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-1', 'deploy_tool', [], null),
        ]),
    ]);

    defineWorkflow('deploy-fanout', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->parallel([DeployAgent::class], mode: 'sync')
        ->step(FinalizeStep::class));

    try {
        AgentWorkflow::start('deploy-fanout', ['prompt' => 'Deploy the app']);
    } catch (WorkflowException) {
        // surfaces on the sync queue
    }

    $run = WorkflowRun::sole();

    // Loud failure, not a silent mid-turn completion: the branch is
    // failed with a pointer at the unsupported composition, nothing is
    // recorded as completed, and no interrupt was stacked.
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failure_reason)->toContain('not supported inside parallel branches')
        ->and($run->steps()->where('step_id', 'DeployAgent')->sole()->status)->toBe(StepStatus::Failed)
        ->and($run->interrupts()->count())->toBe(0)
        ->and($run->steps()->where('step_id', 'FinalizeStep')->count())->toBe(0);
});
