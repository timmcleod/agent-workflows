<?php

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\AgentResponse;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Enums\StepStatus;
use TimMcLeod\AgentWorkflows\Exceptions\DefinitionDriftException;
use TimMcLeod\AgentWorkflows\Exceptions\MissingWorkflowPromptException;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\Steps\DebateRoundStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\BearCaseAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\BullCaseAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\DeployAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\VerdictAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\FinalizeStep;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Steps\PrepareStep;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;
use TimMcLeod\AgentWorkflows\WorkflowState;

function defineDebate(int $rounds = 4, ?Closure $until = null, ?int $transcriptWindow = null): void
{
    defineWorkflow('acquisition', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->debate(
            ['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class],
            judge: VerdictAgent::class,
            as: 'thesis',
            rounds: $rounds,
            topic: fn (WorkflowState $s) => 'Should we acquire X?',
            until: $until,
            transcriptWindow: $transcriptWindow,
        )
        ->step(FinalizeStep::class));
}

it('debates to consensus, checkpointing every round', function () {
    BullCaseAgent::fake(['Bull r1.', 'Bull r2.']);
    BearCaseAgent::fake(['Bear r1.', 'Bear r2.']);
    VerdictAgent::fake([
        ['consensus' => false, 'summary' => 'Still apart.'],
        ['consensus' => true, 'summary' => 'Agreed: acquire.'],
    ]);

    defineDebate();

    $run = AgentWorkflow::start('acquisition', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps']['thesis']['satisfied'])->toBeTrue()
        ->and($run->state['steps']['thesis']['iteration'])->toBe(2)
        ->and($run->state['finalized'])->toBeTrue();

    // The documented reading surface: output() and the judge subtree.
    $state = WorkflowState::make($run->state);

    expect($state->output('thesis')?->get('judge.consensus'))->toBeTrue()
        ->and($state->output('thesis')?->get('judge.summary'))->toBe('Agreed: acquire.');

    // 2 rounds × 2 speakers, in speaking order.
    expect($run->state['steps']['thesis']['transcript'])->toBe([
        ['speaker' => 'bull', 'round' => 1, 'text' => 'Bull r1.'],
        ['speaker' => 'bear', 'round' => 1, 'text' => 'Bear r1.'],
        ['speaker' => 'bull', 'round' => 2, 'text' => 'Bull r2.'],
        ['speaker' => 'bear', 'round' => 2, 'text' => 'Bear r2.'],
    ]);

    // Every round is its own audit row, with aggregated usage recorded.
    $rows = $run->steps()->where('step_id', 'thesis')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($row) => $row->status === StepStatus::Completed))->toBeTrue()
        ->and($rows->first()->usage)->toBeArray()->toHaveKey('prompt_tokens');
});

it('prompts openings in round 1 and rebuttals against the transcript afterwards', function () {
    BullCaseAgent::fake(['Bull r1.', 'Bull r2.']);
    BearCaseAgent::fake(['Bear r1.', 'Bear r2.']);
    VerdictAgent::fake([
        ['consensus' => false, 'summary' => 'Still apart.'],
        ['consensus' => true, 'summary' => 'Agreed.'],
    ]);

    defineDebate();

    AgentWorkflow::start('acquisition', []);

    // Round 1, first speaker: topic + opening protocol, no transcript yet.
    BullCaseAgent::assertPrompted(fn ($p) => str_contains((string) $p->prompt, 'Should we acquire X?')
        && str_contains((string) $p->prompt, 'opening position')
        && ! str_contains((string) $p->prompt, 'Transcript'));

    // Round 1, second speaker: already sees the first opening mid-round.
    BearCaseAgent::assertPrompted(fn ($p) => str_contains((string) $p->prompt, 'bull (round 1): Bull r1.')
        && str_contains((string) $p->prompt, 'opening position'));

    // Round 2: rebuttal protocol against the full round-1 transcript.
    BullCaseAgent::assertPrompted(fn ($p) => str_contains((string) $p->prompt, 'Rebut and revise')
        && str_contains((string) $p->prompt, 'bear (round 1): Bear r1.'));

    // The judge rules on the whole transcript.
    VerdictAgent::assertPrompted(fn ($p) => str_contains((string) $p->prompt, 'bull (round 2): Bull r2.')
        && str_contains((string) $p->prompt, 'reached consensus'));
});

it('treats hitting the round cap as an outcome, not a failure', function () {
    BullCaseAgent::fake(['Bull r1.', 'Bull r2.']);
    BearCaseAgent::fake(['Bear r1.', 'Bear r2.']);
    VerdictAgent::fake([
        ['consensus' => false, 'summary' => 'Apart.'],
        ['consensus' => false, 'summary' => 'Still apart.'],
    ]);

    defineDebate(rounds: 2);

    $run = AgentWorkflow::start('acquisition', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps']['thesis']['satisfied'])->toBeFalse()
        ->and($run->state['steps']['thesis']['iteration'])->toBe(2)
        ->and($run->state['steps']['thesis']['judge']['consensus'])->toBeFalse()
        // The run continued to the next spine step regardless.
        ->and($run->state['finalized'])->toBeTrue();
});

it('lets a custom until: replace the consensus default, skipping the verdict check', function () {
    BullCaseAgent::fake(['Bull r1.', 'Bull r2.']);
    BearCaseAgent::fake(['Bear r1.', 'Bear r2.']);
    // No `consensus` key at all — legal under a custom predicate.
    VerdictAgent::fake([
        ['summary' => 'not yet'],
        ['summary' => 'done'],
    ]);

    defineDebate(until: fn (WorkflowState $s) => $s->get('steps.thesis.judge.summary') === 'done');

    $run = AgentWorkflow::start('acquisition', []);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps']['thesis']['satisfied'])->toBeTrue()
        ->and($run->state['steps']['thesis']['iteration'])->toBe(2);
});

it('fails loudly after one round when the judge verdict lacks consensus under the default predicate', function () {
    BullCaseAgent::fake(['Bull r1.']);
    BearCaseAgent::fake(['Bear r1.']);
    VerdictAgent::fake([['summary' => 'forgot the consensus key']]);

    defineDebate();

    try {
        AgentWorkflow::start('acquisition', []);
        $this->fail('Expected a WorkflowException.');
    } catch (WorkflowException $e) {
        expect($e->getMessage())->toContain('[consensus]')
            ->and($e->getMessage())->toContain(VerdictAgent::class);
    }

    $run = WorkflowRun::sole();

    // Exactly one round was spent: the failure is loud and immediate.
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failed_step)->toBe('thesis')
        ->and($run->steps()->where('step_id', 'thesis')->sole()->status)->toBe(StepStatus::Failed);
});

it('rejects invalid debate definitions at definition time', function () {
    // One debater.
    expect(fn () => (new WorkflowDefinition('w'))->debate(
        ['bull' => BullCaseAgent::class], judge: VerdictAgent::class, as: 'd',
    ))->toThrow(InvalidArgumentException::class, 'at least two debaters');

    // A non-agent debater.
    expect(fn () => (new WorkflowDefinition('w'))->debate(
        [BullCaseAgent::class, FinalizeStep::class], judge: VerdictAgent::class, as: 'd',
    ))->toThrow(InvalidArgumentException::class, 'must be an agent class');

    // Two debaters collapsing to one speaker name.
    expect(fn () => (new WorkflowDefinition('w'))->debate(
        [BullCaseAgent::class, BullCaseAgent::class], judge: VerdictAgent::class, as: 'd',
    ))->toThrow(InvalidArgumentException::class, 'two speakers named');

    // A judge without structured output.
    expect(fn () => (new WorkflowDefinition('w'))->debate(
        [BullCaseAgent::class, BearCaseAgent::class], judge: SummarizeAgent::class, as: 'd',
    ))->toThrow(InvalidArgumentException::class, 'structured');

    // A zero-round debate.
    expect(fn () => (new WorkflowDefinition('w'))->debate(
        [BullCaseAgent::class, BearCaseAgent::class], judge: VerdictAgent::class, as: 'd', rounds: 0,
    ))->toThrow(InvalidArgumentException::class, 'at least one round');

    // A zero-round transcript window.
    expect(fn () => (new WorkflowDefinition('w'))->debate(
        [BullCaseAgent::class, BearCaseAgent::class], judge: VerdictAgent::class, as: 'd', transcriptWindow: 0,
    ))->toThrow(InvalidArgumentException::class, 'transcriptWindow');
});

it('fails at the debate step when no topic source exists', function () {
    BullCaseAgent::fake(['Bull r1.']);

    defineWorkflow('topicless', fn (WorkflowDefinition $workflow) => $workflow
        ->debate(
            ['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class],
            judge: VerdictAgent::class,
            as: 'thesis',
        ));

    // No topic: and no "prompt" state key.
    expect(fn () => AgentWorkflow::start('topicless', []))
        ->toThrow(MissingWorkflowPromptException::class, 'needs a topic');
});

it('changes the definition hash when a debate knob changes', function () {
    $hash = fn (array $debaters, string $topic = 'T', int $rounds = 4, ?int $window = null) => (new WorkflowDefinition('h'))
        ->debate($debaters, judge: VerdictAgent::class, as: 'thesis', rounds: $rounds, topic: $topic, transcriptWindow: $window)
        ->hash();

    $base = $hash(['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class]);

    // Adding a debater, renaming a speaker, changing rounds, the window, or
    // a string topic: all resumable-behavior changes, all must move the hash.
    expect($hash(['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class, 'quant' => SummarizeAgent::class]))->not->toBe($base)
        ->and($hash(['optimist' => BullCaseAgent::class, 'bear' => BearCaseAgent::class]))->not->toBe($base)
        ->and($hash(['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class], rounds: 5))->not->toBe($base)
        ->and($hash(['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class], window: 2))->not->toBe($base)
        ->and($hash(['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class], topic: 'Other question'))->not->toBe($base)
        // Same knobs → same hash (the fingerprint is stable).
        ->and($hash(['bull' => BullCaseAgent::class, 'bear' => BearCaseAgent::class]))->toBe($base);
});

it('hashes the shipped protocol-prompt version so upgrades trip strict drift', function () {
    defineDebate();

    $body = app(WorkflowRegistry::class)
        ->get('acquisition')
        ->findStep('thesis')
        ->body;

    expect($body->fingerprint()['protocol'])->toBe(DebateRoundStep::PROTOCOL_VERSION);
});

it('refuses to resume a run whose debate drifted, in strict mode', function () {
    BullCaseAgent::fake(['Bull r1.']);
    BearCaseAgent::fake(['Bear r1.']);
    VerdictAgent::fake([['summary' => 'no consensus key']]);

    defineDebate();

    try {
        AgentWorkflow::start('acquisition', []);
    } catch (WorkflowException) {
        // expected — parks the run as failed
    }

    // A deploy widens the debate while the run is parked.
    defineDebate(rounds: 6);

    expect(fn () => WorkflowRun::sole()->retry())->toThrow(DefinitionDriftException::class);
});

it('windows the debater prompts to the last rounds while the judge sees everything', function () {
    BullCaseAgent::fake(['Bull r1.', 'Bull r2.', 'Bull r3.']);
    BearCaseAgent::fake(['Bear r1.', 'Bear r2.', 'Bear r3.']);
    VerdictAgent::fake([
        ['consensus' => false, 'summary' => 'Apart.'],
        ['consensus' => false, 'summary' => 'Apart.'],
        ['consensus' => true, 'summary' => 'Agreed.'],
    ]);

    defineDebate(rounds: 4, transcriptWindow: 1);

    AgentWorkflow::start('acquisition', []);

    // Round 3, first speaker: sees round 2, not round 1.
    BullCaseAgent::assertPrompted(fn ($p) => str_contains((string) $p->prompt, 'Bear r2.')
        && str_contains((string) $p->prompt, 'Rebut')
        && ! str_contains((string) $p->prompt, 'Bear r1.'));

    // The judge is never windowed: its round-3 prompt still holds round 1.
    VerdictAgent::assertPrompted(fn ($p) => str_contains((string) $p->prompt, 'Bull r3.')
        && str_contains((string) $p->prompt, 'Bull r1.'));
});

it('re-runs a crashed round as a unit on retry, without duplicating committed rounds', function () {
    $crashed = false;

    BullCaseAgent::fake(['Bull r1.', 'Bull r2 lost.', 'Bull r2 retried.']);
    BearCaseAgent::fake(['Bear r1.', 'Bear r2 lost.', 'Bear r2 retried.']);
    VerdictAgent::fake([
        ['consensus' => false, 'summary' => 'Apart.'],
        function () use (&$crashed) {
            if (! $crashed) {
                $crashed = true;

                throw new RuntimeException('Judge provider exploded.');
            }

            return ['consensus' => true, 'summary' => 'Agreed.'];
        },
    ]);

    defineDebate();

    try {
        AgentWorkflow::start('acquisition', []);
        $this->fail('Expected the judge crash to fail the run.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Judge provider exploded.');
    }

    $run = WorkflowRun::sole();

    // The checkpoint holds only the committed round — the crashed round's
    // partial transcript was discarded with its state.
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failed_step)->toBe('thesis')
        ->and(array_column($run->state['steps']['thesis']['transcript'], 'text'))
        ->toBe(['Bull r1.', 'Bear r1.']);

    $run = $run->retry();

    // Round 2 re-ran as a unit: fresh debater turns, no duplicates from
    // round 1, and the round has failed + completed attempts on record.
    expect($run->status)->toBe(RunStatus::Completed)
        ->and(array_column($run->state['steps']['thesis']['transcript'], 'text'))
        ->toBe(['Bull r1.', 'Bear r1.', 'Bull r2 retried.', 'Bear r2 retried.'])
        ->and($run->state['steps']['thesis']['satisfied'])->toBeTrue()
        ->and($run->steps()->where('step_id', 'thesis')->orderBy('id')->pluck('status')->all())
        ->toBe([StepStatus::Completed, StepStatus::Failed, StepStatus::Completed]);
});

it('rejects approval-gated debaters loudly, checkpointing nothing', function () {
    // DeployAgent is Conversational — the SDK only pauses agents that can
    // resume from history.
    DeployAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-1', 'research_tool', [], 'Needs sign-off'),
        ]),
    ]);

    defineWorkflow('gated-debate', fn (WorkflowDefinition $workflow) => $workflow
        ->debate(
            ['bull' => DeployAgent::class, 'bear' => BearCaseAgent::class],
            judge: VerdictAgent::class,
            as: 'thesis',
            topic: 'Should we acquire X?',
        ));

    try {
        AgentWorkflow::start('gated-debate', []);
        $this->fail('Expected a WorkflowException.');
    } catch (WorkflowException $e) {
        expect($e->getMessage())->toContain('debater [bull]')
            ->and($e->getMessage())->toContain('approval');
    }

    $run = WorkflowRun::sole();

    expect($run->status)->toBe(RunStatus::Failed)
        // Nothing was checkpointed as completed and no interrupt was parked.
        ->and($run->state['steps'] ?? [])->not->toHaveKey('thesis')
        ->and($run->interrupts()->count())->toBe(0)
        ->and($run->steps()->where('step_id', 'thesis')->sole()->status)->toBe(StepStatus::Failed);
});

it('counts debate rounds through WorkflowFake::assertStepRanTimes', function () {
    $fake = AgentWorkflow::fake();

    BullCaseAgent::fake(['Bull r1.', 'Bull r2.']);
    BearCaseAgent::fake(['Bear r1.', 'Bear r2.']);
    VerdictAgent::fake([
        ['consensus' => false, 'summary' => 'Apart.'],
        ['consensus' => true, 'summary' => 'Agreed.'],
    ]);

    defineDebate();

    AgentWorkflow::start('acquisition', []);

    $fake->assertStepRanTimes('thesis', 2);
});

it('runs a debate to consensus on the database queue driver', function () {
    config()->set('queue.default', 'database');

    BullCaseAgent::fake(['Bull r1.', 'Bull r2.']);
    BearCaseAgent::fake(['Bear r1.', 'Bear r2.']);
    VerdictAgent::fake([
        ['consensus' => false, 'summary' => 'Apart.'],
        ['consensus' => true, 'summary' => 'Agreed.'],
    ]);

    defineDebate();

    $run = AgentWorkflow::start('acquisition', []);

    expect($run->status)->toBe(RunStatus::Pending);

    // Drain the queue the way a worker would, one job at a time.
    foreach (range(1, 10) as $i) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

        if ($run->refresh()->status->isTerminal()) {
            break;
        }
    }

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps']['thesis']['iteration'])->toBe(2)
        ->and($run->state['steps']['thesis']['satisfied'])->toBeTrue()
        ->and($run->state['steps']['thesis']['transcript'])->toHaveCount(4)
        ->and($run->state['finalized'])->toBeTrue();
});
