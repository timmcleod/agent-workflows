<?php

namespace TimMcLeod\AgentWorkflows;

use Closure;
use Illuminate\Support\Str;
use TimMcLeod\AgentWorkflows\Enums\StepType;

class StepDefinition
{
    /**
     * @param  class-string|null  $target
     * @param  Closure(WorkflowState): string|string|null  $prompt  agent steps only
     * @param  string|null  $label  human-facing label for progress displays
     */
    public function __construct(
        public readonly string $id,
        public readonly StepType $type,
        public readonly ?string $target = null,
        public readonly Closure|string|null $prompt = null,
        public readonly ?string $label = null,
    ) {}

    /**
     * The human-facing label for progress displays. An explicit label wins;
     * otherwise the step id humanizes ("GatherTicketContext" becomes
     * "Gather ticket context"). Structural subclasses substitute
     * purpose-built defaults for their positional engine ids.
     *
     * Labels are cosmetic and deliberately absent from fingerprint():
     * adding or editing them must never strand in-flight runs in strict
     * drift mode.
     */
    public function displayLabel(): string
    {
        return $this->label ?? static::humanizeId($this->id);
    }

    protected static function humanizeId(string $id): string
    {
        return Str::ucfirst(Str::lower(Str::headline(str_replace(':', ' ', $id))));
    }

    /**
     * Nested steps addressable by id (condition branches, parallel branches).
     *
     * @return array<int, StepDefinition>
     */
    public function children(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function fingerprint(): array
    {
        return array_filter([
            'id' => $this->id,
            'type' => $this->type->value,
            'target' => $this->target,
            // A string prompt is part of the step's behavior; closure
            // bodies cannot be hashed (documented limitation).
            'prompt' => $this->prompt instanceof Closure ? '(closure)' : $this->prompt,
        ], fn (mixed $value) => $value !== null);
    }
}
