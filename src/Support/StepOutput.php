<?php

namespace TimMcLeod\AgentWorkflows\Support;

use Illuminate\Support\Arr;

/**
 * A read-only view over one step's checkpointed output in the state bag
 * (everything under "steps.{id}"), so callers never spell structural paths
 * like "steps.RiskAnalysisAgent.structured.riskScore" by hand.
 */
class StepOutput
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(protected array $data) {}

    /**
     * The step's text output (agent steps).
     */
    public function text(): ?string
    {
        $text = $this->data['text'] ?? null;

        return is_string($text) ? $text : null;
    }

    /**
     * The step's structured output (agents with structured output). With a
     * key, returns that field ("riskScore", dot-notation supported).
     */
    public function structured(?string $key = null, mixed $default = null): mixed
    {
        $structured = $this->data['structured'] ?? null;

        if ($key === null) {
            return $structured;
        }

        return is_array($structured) ? Arr::get($structured, $key, $default) : $default;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }
}
