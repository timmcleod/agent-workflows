<?php

use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\AgentResponse;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\BearCaseAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\BullCaseAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\DeployAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\VerdictAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

it('records one call audit entry per provider call on an agent step row', function () {
    DeployAgent::fake(['Deployed.']);

    defineWorkflow('deploy', fn (WorkflowDefinition $workflow) => $workflow
        ->step(DeployAgent::class, prompt: 'Deploy the app'));

    $run = AgentWorkflow::start('deploy', []);

    $calls = $run->steps()->sole()->calls;

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['invocation_id'])->toBeString()->not->toBe('')
        ->and($calls[0]['provider'])->toBeString()
        ->and($calls[0]['model'])->toBeString()
        ->and($calls[0]['finish_reason'])->toBeString()
        ->and($calls[0]['usage'])->toHaveKey('prompt_tokens');
});

it('records no call audit on steps that make no provider calls', function () {
    defineWorkflow('plain', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    $run = AgentWorkflow::start('plain', []);

    expect($run->steps()->sole()->calls)->toBeNull();
});

it('records the billed usage on an attempt parked by a tool-approval pause', function () {
    DeployAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-1', 'deploy_tool', ['env' => 'production'], null),
        ]),
        'Deployed successfully.',
    ]);

    defineWorkflow('deploy', fn (WorkflowDefinition $workflow) => $workflow
        ->step(DeployAgent::class, prompt: 'Deploy the app'));

    $run = AgentWorkflow::start('deploy', []);

    $parked = $run->steps()->sole();

    // The pause follows a completed, billed provider call: the parked
    // attempt must carry its usage rather than recording nothing.
    expect($run->status)->toBe(RunStatus::AwaitingHuman)
        ->and($parked->status)->toBe(StepStatus::Interrupted)
        ->and($parked->usage)->not->toBeNull()
        ->and($parked->usage)->toHaveKey('prompt_tokens');

    $run = $run->resume(['call-1' => true]);

    // The resumed attempt is a separate row with its own call audit.
    $rows = $run->steps()->orderBy('id')->get();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($rows)->toHaveCount(2)
        ->and($rows[1]->status)->toBe(StepStatus::Completed)
        ->and($rows[1]->calls)->toHaveCount(1);
});

it('records one entry per debater plus the judge on a debate round row', function () {
    BullCaseAgent::fake(['Bull r1.']);
    BearCaseAgent::fake(['Bear r1.']);
    VerdictAgent::fake([['consensus' => true, 'summary' => 'Agreed.']]);

    defineWorkflow('acquisition', fn (WorkflowDefinition $workflow) => $workflow
        ->debate(
            ['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class],
            judge: VerdictAgent::class,
            as: 'thesis',
            rounds: 2,
            topic: fn (WorkflowState $s) => 'Should we acquire X?',
        ));

    $run = AgentWorkflow::start('acquisition', []);

    $calls = $run->steps()->where('step_id', 'thesis')->sole()->calls;

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($calls)->toHaveCount(3)
        ->and(collect($calls)->pluck('invocation_id')->unique())->toHaveCount(3);
});

it('tolerates a steps table whose calls migration has not run yet', function () {
    Schema::table(config('agent-workflows.tables.steps'), function ($table) {
        $table->dropColumn('calls');
    });

    DeployAgent::fake(['Deployed.']);

    defineWorkflow('deploy', fn (WorkflowDefinition $workflow) => $workflow
        ->step(DeployAgent::class, prompt: 'Deploy the app')
        ->step(FinalizeStep::class));

    $run = AgentWorkflow::start('deploy', []);

    $row = $run->steps()->where('step_id', 'DeployAgent')->sole();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($row->status)->toBe(StepStatus::Completed)
        ->and($row->usage)->toHaveKey('prompt_tokens');
});

it('sums usage but concatenates call audits across an evaluate loop iteration', function () {
    SummarizeAgent::fake(['Draft one.', 'Draft two.']);

    defineWorkflow('drafting', fn (WorkflowDefinition $workflow) => $workflow
        ->evaluate(
            SummarizeAgent::class,
            until: fn (WorkflowState $state) => ($state->get('steps.SummarizeAgent.text') ?? '') === 'Draft two.',
            maxIterations: 3,
            prompt: 'Write the draft',
        ));

    $run = AgentWorkflow::start('drafting', []);

    $rows = $run->steps()->orderBy('id')->get();

    // Each iteration is its own attempt row carrying only its own call.
    expect($run->status)->toBe(RunStatus::Completed)
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]->calls)->toHaveCount(1)
        ->and($rows[1]->calls)->toHaveCount(1)
        ->and($rows[0]->calls[0]['invocation_id'])->not->toBe($rows[1]->calls[0]['invocation_id']);
});
