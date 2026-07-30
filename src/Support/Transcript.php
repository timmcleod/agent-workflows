<?php

namespace TimMcLeod\AgentWorkflows\Support;

use TimMcLeod\AgentWorkflows\WorkflowState;

/**
 * A view over one step's debate transcript in the state bag (everything
 * under "steps.{id}.transcript"), so round bodies and downstream prompts
 * never spell the entry shape or structural paths by hand. Entries are
 * plain arrays — the transcript survives JSON checkpointing as-is.
 *
 * @phpstan-consistent-constructor
 */
class Transcript
{
    protected function __construct(
        protected WorkflowState $state,
        protected string $key,
    ) {}

    /**
     * The transcript of a step, addressed by step class or id — the same
     * normalization as WorkflowState::output().
     */
    public static function in(WorkflowState $state, string $step): static
    {
        $id = class_exists($step) ? class_basename($step) : $step;

        return new static($state, 'steps.'.$id.'.transcript');
    }

    public function append(string $speaker, int $round, string $text): static
    {
        $entries = $this->entries();

        $entries[] = ['speaker' => $speaker, 'round' => $round, 'text' => $text];

        $this->state->set($this->key, $entries);

        return $this;
    }

    /**
     * @return array<int, array{speaker: string, round: int, text: string}>
     */
    public function entries(): array
    {
        $entries = $this->state->get($this->key, []);

        return is_array($entries) ? array_values($entries) : [];
    }

    /**
     * @return array<int, array{speaker: string, round: int, text: string}>
     */
    public function bySpeaker(string $speaker): array
    {
        return array_values(array_filter(
            $this->entries(),
            fn (array $entry) => $entry['speaker'] === $speaker,
        ));
    }

    /**
     * @return array<int, array{speaker: string, round: int, text: string}>
     */
    public function round(int $round): array
    {
        return array_values(array_filter(
            $this->entries(),
            fn (array $entry) => $entry['round'] === $round,
        ));
    }

    public function count(): int
    {
        return count($this->entries());
    }

    /**
     * The "SPEAKER (round N): text" block used in prompts. With $lastRounds,
     * only the most recent N rounds are rendered — the lever that bounds
     * prompt growth in long debates.
     */
    public function render(?int $lastRounds = null): string
    {
        $entries = $this->entries();

        if ($lastRounds !== null && $entries !== []) {
            $cutoff = max(array_column($entries, 'round')) - $lastRounds;

            $entries = array_filter($entries, fn (array $entry) => $entry['round'] > $cutoff);
        }

        return implode("\n\n", array_map(
            fn (array $entry) => "{$entry['speaker']} (round {$entry['round']}): {$entry['text']}",
            $entries,
        ));
    }
}
