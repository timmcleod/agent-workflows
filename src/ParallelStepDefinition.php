<?php

namespace TimMcLeod\AgentWorkflows;

use Closure;
use TimMcLeod\AgentWorkflows\Enums\StepType;

class ParallelStepDefinition extends StepDefinition
{
    /**
     * @param  array<int, StepDefinition>  $branches
     * @param  Closure(array<string, array<string, mixed>>, array<string, mixed>): (WorkflowState|array<string, mixed>)|null  $merge
     * @param  'batch'|'sync'  $mode
     */
    public function __construct(
        string $id,
        public readonly array $branches,
        public readonly ?Closure $merge = null,
        public readonly string $mode = 'batch',
    ) {
        parent::__construct($id, StepType::Parallel);
    }

    public function children(): array
    {
        return $this->branches;
    }

    public function branch(string $id): StepDefinition
    {
        foreach ($this->branches as $branch) {
            if ($branch->id === $id) {
                return $branch;
            }
        }

        throw new Exceptions\WorkflowException("Parallel step [{$this->id}] has no branch [{$id}].");
    }

    public function fingerprint(): array
    {
        return [
            ...parent::fingerprint(),
            'branches' => array_map(fn (StepDefinition $branch) => $branch->fingerprint(), $this->branches),
            'mode' => $this->mode,
        ];
    }
}
