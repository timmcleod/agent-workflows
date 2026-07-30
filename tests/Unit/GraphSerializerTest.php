<?php

use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\BearCaseAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\BullCaseAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\VerdictAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\BranchAStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\BranchBStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\RefineStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\TransformStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

it('serializes a sequential workflow into single-node rows and chained edges', function () {
    $graph = (new WorkflowDefinition('seq'))
        ->step(PrepareStep::class)
        ->step(TransformStep::class)
        ->step(FinalizeStep::class)
        ->toGraph();

    expect($graph['name'])->toBe('seq')
        ->and($graph['rows'])->toBe([['PrepareStep'], ['TransformStep'], ['FinalizeStep']])
        ->and($graph['edges'])->toBe([
            ['from' => 'PrepareStep', 'to' => 'TransformStep', 'label' => null],
            ['from' => 'TransformStep', 'to' => 'FinalizeStep', 'label' => null],
        ])
        ->and($graph['nodes']['PrepareStep']['type'])->toBe('callback');
});

it('matches the definition hash so consumers can detect drift', function () {
    $definition = (new WorkflowDefinition('hashed'))->step(PrepareStep::class);

    expect($definition->toGraph()['hash'])->toBe($definition->hash());
});

it('labels condition branch edges yes/no and places branches on a shared row', function () {
    $graph = (new WorkflowDefinition('cond'))
        ->step(PrepareStep::class)
        ->when(fn ($state) => true, then: BranchAStep::class, else: BranchBStep::class, as: 'gate')
        ->step(FinalizeStep::class)
        ->toGraph();

    expect($graph['rows'])->toBe([['PrepareStep'], ['gate'], ['BranchAStep', 'BranchBStep'], ['FinalizeStep']])
        ->and($graph['edges'])->toContain(
            ['from' => 'gate', 'to' => 'BranchAStep', 'label' => 'yes'],
            ['from' => 'gate', 'to' => 'BranchBStep', 'label' => 'no'],
            ['from' => 'BranchAStep', 'to' => 'FinalizeStep', 'label' => null],
            ['from' => 'BranchBStep', 'to' => 'FinalizeStep', 'label' => null],
        )
        ->and($graph['nodes']['BranchAStep']['branchOf'])->toBe('gate');
});

it('routes a no-labelled skip edge past an else-less condition', function () {
    $graph = (new WorkflowDefinition('skip'))
        ->when(fn ($state) => true, then: BranchAStep::class, as: 'gate')
        ->step(FinalizeStep::class)
        ->toGraph();

    expect($graph['edges'])->toContain(
        ['from' => 'BranchAStep', 'to' => 'FinalizeStep', 'label' => null],
        ['from' => 'gate', 'to' => 'FinalizeStep', 'label' => 'no'],
    );
});

it('fans parallel branches out on one row and back into the next step', function () {
    $graph = (new WorkflowDefinition('par'))
        ->parallel([BranchAStep::class, BranchBStep::class], as: 'fan')
        ->step(FinalizeStep::class)
        ->toGraph();

    expect($graph['rows'])->toBe([['fan'], ['BranchAStep', 'BranchBStep'], ['FinalizeStep']])
        ->and($graph['nodes']['fan']['detail'])->toBe('2 branches · batch')
        ->and($graph['edges'])->toContain(
            ['from' => 'fan', 'to' => 'BranchAStep', 'label' => null],
            ['from' => 'BranchAStep', 'to' => 'FinalizeStep', 'label' => null],
            ['from' => 'BranchBStep', 'to' => 'FinalizeStep', 'label' => null],
        );
});

it('describes evaluate, await, and agent nodes for display', function () {
    $graph = (new WorkflowDefinition('desc'))
        ->step(SummarizeAgent::class, prompt: fn ($state) => 'Summarize.')
        ->evaluate(RefineStep::class, until: fn ($state) => true, maxIterations: 5, as: 'refine')
        ->awaitHuman(reason: 'Sign off', schema: ['approved' => 'required|boolean'], as: 'gate')
        ->awaitEvent('payment.settled', as: 'settled')
        ->toGraph();

    expect($graph['nodes']['SummarizeAgent'])->toMatchArray(['type' => 'agent', 'target' => 'SummarizeAgent', 'detail' => 'dynamic prompt'])
        ->and($graph['nodes']['refine'])->toMatchArray(['type' => 'evaluate', 'target' => 'RefineStep', 'detail' => 'loop until satisfied · max 5×'])
        ->and($graph['nodes']['gate'])->toMatchArray(['type' => 'await_human', 'detail' => 'Sign off', 'schema' => ['approved' => 'required|boolean']])
        ->and($graph['nodes']['settled'])->toMatchArray(['type' => 'await_event', 'detail' => 'event: payment.settled']);
});

it('truncates string prompts into the node detail', function () {
    $graph = (new WorkflowDefinition('prompted'))
        ->step(SummarizeAgent::class, prompt: str_repeat('Summarize the weekly report. ', 5))
        ->toGraph();

    expect($graph['nodes']['SummarizeAgent']['detail'])
        ->toEndWith('...')
        ->and(strlen($graph['nodes']['SummarizeAgent']['detail']))->toBeLessThan(70);
});

it('renders a debate step as an evaluate-shaped node with a debate detail', function () {
    $graph = (new WorkflowDefinition('debated'))
        ->step(PrepareStep::class)
        ->debate(
            ['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class],
            judge: VerdictAgent::class,
            as: 'thesis',
            rounds: 4,
        )
        ->toGraph();

    expect($graph['rows'])->toBe([['PrepareStep'], ['thesis']])
        ->and($graph['nodes']['thesis']['type'])->toBe('evaluate')
        ->and($graph['nodes']['thesis']['target'])->toBe('VerdictAgent')
        ->and($graph['nodes']['thesis']['detail'])->toBe('debate · 2 voices · max 4 rounds');
});
