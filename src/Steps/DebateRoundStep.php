<?php

namespace TimMcLeod\AgentWorkflows\Steps;

use Illuminate\Contracts\Container\Container;
use Laravel\Ai\Contracts\Agent;
use TimMcLeod\AgentWorkflows\DebateRoundDefinition;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Support\AgentAdapter;
use TimMcLeod\AgentWorkflows\Support\AgentStepResult;
use TimMcLeod\AgentWorkflows\Support\ResolvesPrompts;
use TimMcLeod\AgentWorkflows\Support\Transcript;
use TimMcLeod\AgentWorkflows\WorkflowState;

/**
 * The package-shipped body of a debate() step. One invocation is one round:
 * every debater speaks in order against the transcript so far, then the
 * judge rules on the whole debate. The wrapping evaluate step checkpoints
 * each round and decides whether to loop.
 */
class DebateRoundStep
{
    use ResolvesPrompts;

    /**
     * Bump whenever any shipped protocol prompt below changes. The version
     * is part of the debate fingerprint, so strict drift mode refuses to
     * resume an in-flight debate across a prompt change.
     */
    public const PROTOCOL_VERSION = 1;

    public function __construct(
        protected Container $container,
        protected AgentAdapter $adapter,
    ) {}

    public function __invoke(WorkflowState $state, DebateRoundDefinition $step): StepResult
    {
        // runEvaluate writes the iteration counter after the body runs, so
        // the checkpointed count is the number of committed rounds.
        $round = (int) $state->get('steps.'.$step->id.'.iteration', 0) + 1;

        $transcript = Transcript::in($state, $step->id);

        $results = [];

        foreach ($step->debaters as $speaker => $class) {
            /** @var Agent $debater */
            $debater = $this->container->make($class);

            $result = $this->adapter->prompt(
                $debater,
                $this->debaterPrompt($step, $state, $transcript, $speaker, $round),
            );

            $this->rejectPendingApprovals($step, $result, "debater [{$speaker}]");

            // An empty response is appended as-is: throwing would waste the
            // whole round on a transient blank, and skipping would desync
            // the round-robin accounting. The judge sees the hollow entry.
            $transcript->append($speaker, $round, $result->text);

            $results[] = $result;
        }

        /** @var Agent $judge */
        $judge = $this->container->make($step->judge);

        $verdict = $this->adapter->prompt($judge, $this->judgePrompt($step, $state, $transcript));

        $this->rejectPendingApprovals($step, $verdict, 'the judge');

        $results[] = $verdict;

        $state->set('steps.'.$step->id.'.judge', $verdict->structured);

        // Fail after one round, not all of them: under the default predicate
        // a verdict without `consensus` reads as null → never satisfied →
        // every remaining round's tokens spent on a configuration error.
        if ($step->defaultUntil && ! array_key_exists('consensus', $verdict->structured ?? [])) {
            throw new WorkflowException(
                "Debate step [{$step->id}] judge [{$step->judge}] produced a verdict without a ".
                "[consensus] boolean; add it to the judge's schema or pass a custom until: predicate."
            );
        }

        return new StepResult($state, AgentStepResult::sum(...$results), calls: AgentStepResult::calls(...$results));
    }

    /**
     * Approval-gated participants are a configuration error in v1. The
     * interrupt channel itself exists — a callback may return a StepResult
     * carrying a PendingInterrupt — but debate rounds lack the replay
     * bookkeeping to route decisions back to one speaker and skip those who
     * already spoke this round.
     */
    protected function rejectPendingApprovals(DebateRoundDefinition $step, AgentStepResult $result, string $who): void
    {
        if (! $result->hasPendingApprovals()) {
            return;
        }

        throw new WorkflowException(
            "Debate step [{$step->id}]: {$who} paused on tool approvals; debate rounds cannot yet replay ".
            'approval decisions per speaker — move approval-gated tools out of debate participants.'
        );
    }

    protected function debaterPrompt(
        DebateRoundDefinition $step,
        WorkflowState $state,
        Transcript $transcript,
        string $speaker,
        int $round,
    ): string {
        $override = $round === 1 ? $step->openingPrompt : $step->rebuttalPrompt;

        if ($override !== null) {
            return $override($state, $transcript, $speaker);
        }

        $topic = $this->topic($step, $state);

        // transcriptWindow bounds only the debaters' prompts — the judge
        // always rules on the full transcript.
        $rendered = $transcript->render($step->transcriptWindow);

        $protocol = $round === 1
            ? "You are \"{$speaker}\" in a structured debate. State your opening position on the topic."
            : "You are \"{$speaker}\" in a structured debate. Rebut and revise your position, addressing the latest arguments.";

        return $rendered === ''
            ? "{$topic}\n\n{$protocol}"
            : "{$topic}\n\nTranscript so far:\n\n{$rendered}\n\n{$protocol}";
    }

    protected function judgePrompt(DebateRoundDefinition $step, WorkflowState $state, Transcript $transcript): string
    {
        if ($step->judgePrompt !== null) {
            return ($step->judgePrompt)($state, $transcript);
        }

        return $this->topic($step, $state)
            ."\n\nTranscript:\n\n".$transcript->render()
            ."\n\nYou are the judge. Deliver your structured verdict on the debate, "
            .'including whether the panel has reached consensus.';
    }

    protected function topic(DebateRoundDefinition $step, WorkflowState $state): string
    {
        return $this->resolvePromptSource(
            $step->topic,
            $state,
            "Debate step [{$step->id}]",
            "Debate step [{$step->id}] needs a topic: pass topic: when defining the step, ".
            'or provide a string under the state\'s "prompt" key.'
        );
    }
}
