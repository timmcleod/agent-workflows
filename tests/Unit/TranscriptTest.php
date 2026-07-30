<?php

use TimMcLeod\AgentWorkflows\Support\Transcript;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\WorkflowState;

it('appends entries through to the state bag under the step key', function () {
    $state = WorkflowState::make();

    Transcript::in($state, 'thesis')
        ->append('bull', 1, 'Buy.')
        ->append('bear', 1, 'Sell.');

    expect($state->get('steps.thesis.transcript'))->toBe([
        ['speaker' => 'bull', 'round' => 1, 'text' => 'Buy.'],
        ['speaker' => 'bear', 'round' => 1, 'text' => 'Sell.'],
    ]);
});

it('reads entries a previous round already checkpointed', function () {
    $state = WorkflowState::make([
        'steps' => ['thesis' => ['transcript' => [
            ['speaker' => 'bull', 'round' => 1, 'text' => 'Buy.'],
        ]]],
    ]);

    $transcript = Transcript::in($state, 'thesis');

    expect($transcript->count())->toBe(1)
        ->and($transcript->entries())->toBe([
            ['speaker' => 'bull', 'round' => 1, 'text' => 'Buy.'],
        ]);
});

it('normalizes a step class to its basename id, like output()', function () {
    $state = WorkflowState::make();

    Transcript::in($state, SummarizeAgent::class)->append('bull', 1, 'Buy.');

    expect($state->get('steps.SummarizeAgent.transcript'))->toHaveCount(1);
});

it('filters by speaker and by round', function () {
    $state = WorkflowState::make();

    Transcript::in($state, 'thesis')
        ->append('bull', 1, 'Buy.')
        ->append('bear', 1, 'Sell.')
        ->append('bull', 2, 'Still buy.');

    $transcript = Transcript::in($state, 'thesis');

    expect($transcript->bySpeaker('bull'))->toHaveCount(2)
        ->and($transcript->bySpeaker('bear'))->toHaveCount(1)
        ->and($transcript->round(2))->toBe([
            ['speaker' => 'bull', 'round' => 2, 'text' => 'Still buy.'],
        ]);
});

it('renders the speaker/round block used in prompts', function () {
    $state = WorkflowState::make();

    Transcript::in($state, 'thesis')
        ->append('bull', 1, 'Buy.')
        ->append('bear', 1, 'Sell.');

    expect(Transcript::in($state, 'thesis')->render())
        ->toBe("bull (round 1): Buy.\n\nbear (round 1): Sell.");
});

it('renders only the most recent rounds when asked', function () {
    $state = WorkflowState::make();

    Transcript::in($state, 'thesis')
        ->append('bull', 1, 'Opening buy.')
        ->append('bear', 1, 'Opening sell.')
        ->append('bull', 2, 'Rebuttal buy.')
        ->append('bear', 3, 'Closing sell.');

    $rendered = Transcript::in($state, 'thesis')->render(lastRounds: 2);

    expect($rendered)->toContain('Rebuttal buy.')
        ->and($rendered)->toContain('Closing sell.')
        ->and($rendered)->not->toContain('Opening');
});

it('renders an empty transcript as an empty string', function () {
    $state = WorkflowState::make();

    expect(Transcript::in($state, 'thesis')->render())->toBe('')
        ->and(Transcript::in($state, 'thesis')->render(lastRounds: 2))->toBe('')
        ->and(Transcript::in($state, 'thesis')->count())->toBe(0);
});
