<?php

namespace TimMcLeod\AgentWorkflows;

use Closure;
use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Steps\DebateRoundStep;

/**
 * The config for one debate round. Compiled by WorkflowDefinition::debate()
 * into the body of an evaluate step and executed by Steps\DebateRoundStep
 * through the plain callback executor — no dedicated StepType, so the
 * runtime, drift hashing, sweep, and UI treat a debate as machinery they
 * already know.
 */
class DebateRoundDefinition extends StepDefinition
{
    /**
     * @param  array<string, class-string>  $debaters  speaker alias => agent class, spoken in order
     * @param  class-string  $judge  agent with structured output
     * @param  Closure(WorkflowState): string|string|null  $topic  defaults to the state's "prompt" key
     * @param  ?int  $transcriptWindow  when set, default debater prompts render only the last N rounds
     * @param  bool  $defaultUntil  whether the wrapping evaluate step uses the shipped consensus predicate
     * @param  Closure(WorkflowState, Support\Transcript, string): string|null  $openingPrompt
     * @param  Closure(WorkflowState, Support\Transcript, string): string|null  $rebuttalPrompt
     * @param  Closure(WorkflowState, Support\Transcript): string|null  $judgePrompt
     */
    public function __construct(
        string $id,
        public readonly array $debaters,
        public readonly string $judge,
        public readonly Closure|string|null $topic = null,
        public readonly ?int $transcriptWindow = null,
        public readonly bool $defaultUntil = true,
        public readonly ?Closure $openingPrompt = null,
        public readonly ?Closure $rebuttalPrompt = null,
        public readonly ?Closure $judgePrompt = null,
    ) {
        parent::__construct($id, StepType::Callback, DebateRoundStep::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function fingerprint(): array
    {
        return array_filter([
            ...parent::fingerprint(),
            'debaters' => $this->debaters,
            'judge' => $this->judge,
            // A string topic is part of the step's behavior, like a string
            // prompt; closures cannot be hashed (documented limitation).
            'topic' => $this->topic instanceof Closure ? '(closure)' : $this->topic,
            'transcriptWindow' => $this->transcriptWindow,
            // The shipped protocol prompts are package code: hashing their
            // version means a package upgrade that changes them refuses to
            // resume an in-flight debate under strict drift mode instead of
            // silently altering the next round's prompts.
            'protocol' => DebateRoundStep::PROTOCOL_VERSION,
            'openingPrompt' => $this->openingPrompt !== null ? '(closure)' : null,
            'rebuttalPrompt' => $this->rebuttalPrompt !== null ? '(closure)' : null,
            'judgePrompt' => $this->judgePrompt !== null ? '(closure)' : null,
        ], fn (mixed $value) => $value !== null);
    }
}
