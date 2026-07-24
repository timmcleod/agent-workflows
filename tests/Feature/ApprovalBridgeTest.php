<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\AgentResponse;
use TimMcLeod\AgentWorkflows\Enums\InterruptType;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\DeployAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

beforeEach(function () {
    defineWorkflow('deploy', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->step(DeployAgent::class)
        ->step(FinalizeStep::class));
});

it('surfaces an SDK tool-approval pause as a workflow interrupt', function () {
    DeployAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-1', 'deploy_tool', ['env' => 'production'], 'Production deploys need approval'),
        ]),
        'Deployed successfully.',
    ]);

    $run = AgentWorkflow::start('deploy', ['prompt' => 'Deploy the app']);

    expect($run->status)->toBe(RunStatus::AwaitingHuman)
        ->and($run->current_step)->toBe('DeployAgent')
        ->and($run->steps()->where('step_id', 'DeployAgent')->sole()->status)->toBe(StepStatus::Interrupted)
        ->and($run->steps()->where('step_id', 'FinalizeStep')->count())->toBe(0);

    $interrupt = $run->interrupts()->sole();

    expect($interrupt->type)->toBe(InterruptType::Approval)
        ->and($interrupt->reason)->toContain('deploy_tool')
        ->and($interrupt->context['approvals'][0])->toBe([
            'id' => 'call-1',
            'tool' => 'deploy_tool',
            'arguments' => ['env' => 'production'],
            'reason' => 'Production deploys need approval',
        ]);
});

it('replays the human decisions into the paused agent on resume', function () {
    DeployAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-1', 'deploy_tool', ['env' => 'production'], null),
        ]),
        'Deployed successfully.',
    ]);

    $run = AgentWorkflow::start('deploy', ['prompt' => 'Deploy the app']);

    $run = $run->resume(['call-1' => true]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps']['DeployAgent']['text'])->toBe('Deployed successfully.')
        ->and($run->state['steps']['DeployAgent'])->not->toHaveKey('approval_decisions')
        ->and($run->state['finalized'])->toBeTrue();

    $interrupt = $run->interrupts()->sole();

    expect($interrupt->isResolved())->toBeTrue()
        ->and($interrupt->resolution)->toBe(['call-1' => true]);

    // The agent step's audit trail: interrupted attempt, then completed resume.
    expect($run->steps()->where('step_id', 'DeployAgent')->orderBy('id')->pluck('status')->all())
        ->toBe([StepStatus::Interrupted, StepStatus::Completed]);
});

it('requires a non-empty decisions map to resume an approval interrupt', function () {
    DeployAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-1', 'deploy_tool', [], null),
        ]),
    ]);

    $run = AgentWorkflow::start('deploy', ['prompt' => 'Deploy the app']);

    expect(fn () => $run->resume([]))->toThrow(WorkflowException::class);

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingHuman);
});
