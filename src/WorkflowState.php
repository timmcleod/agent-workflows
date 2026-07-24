<?php

namespace TimMcLeod\AgentWorkflows;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use JsonSerializable;

/**
 * The serializable state bag that flows through a workflow run.
 *
 * State is persisted to the run's `state` JSON column after every step —
 * it is the checkpoint. Steps receive the current state and mutate it
 * (or return a new instance); everything stored in it must be JSON
 * serializable.
 *
 * @implements Arrayable<string, mixed>
 *
 * @phpstan-consistent-constructor
 */
class WorkflowState implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(protected array $data = []) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(array $data = []): static
    {
        return new static($data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    public function set(string $key, mixed $value): static
    {
        Arr::set($this->data, $key, $value);

        return $this;
    }

    public function has(string $key): bool
    {
        return Arr::has($this->data, $key);
    }

    public function forget(string $key): static
    {
        Arr::forget($this->data, $key);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function merge(array $data): static
    {
        $this->data = array_replace($this->data, $data);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    /**
     * A stable hash of the state contents, recorded per step for auditing.
     */
    public function hash(): string
    {
        return hash('sha256', json_encode($this->data, JSON_THROW_ON_ERROR));
    }
}
