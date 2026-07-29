<?php

namespace TimMcLeod\AgentWorkflows\Runtime;

use TimMcLeod\AgentWorkflows\Exceptions\StateMergeConflictException;
use TimMcLeod\AgentWorkflows\ParallelStepDefinition;
use TimMcLeod\AgentWorkflows\WorkflowState;

class StateMerger
{
    /**
     * Merge the branch states of a parallel step back into one state.
     *
     * The default strategy takes the union of each branch's changes against
     * the input snapshot and throws when two branches wrote different values
     * to the same top-level key. Pass a merge closure on the parallel step
     * to resolve conflicts yourself.
     *
     * @param  array<string, mixed>  $input  state snapshot the branches started from
     * @param  array<string, array<string, mixed>>  $branchStates  keyed by branch step id
     * @param  class-string<WorkflowState>  $stateClass
     */
    public function merge(
        ParallelStepDefinition $step,
        array $input,
        array $branchStates,
        string $stateClass = WorkflowState::class,
    ): WorkflowState {
        if ($step->merge !== null) {
            $merged = ($step->merge)($branchStates, $input);

            return $merged instanceof WorkflowState ? $merged : $stateClass::make($merged);
        }

        $merged = $input;
        $writtenBy = [];

        foreach ($branchStates as $branchId => $branchState) {
            foreach ($branchState as $key => $value) {
                if ($key === 'steps') {
                    continue; // engine bookkeeping — merged per step id below
                }

                if (array_key_exists($key, $input) && $input[$key] === $value) {
                    continue; // unchanged from the snapshot
                }

                if (isset($writtenBy[$key]) && $merged[$key] !== $value) {
                    throw new StateMergeConflictException(
                        "Parallel step [{$step->id}]: branches [{$writtenBy[$key]}] and [{$branchId}] both wrote ".
                        "conflicting values to state key [{$key}]. Provide a merge closure to resolve this."
                    );
                }

                $merged[$key] = $value;
                $writtenBy[$key] = $branchId;
            }
        }

        $merged = $this->mergeStepOutputs($step, $input, $branchStates, $merged);

        return $stateClass::make($merged);
    }

    /**
     * Merge the engine-owned "steps" subtree per step id. Every branch
     * checkpoints its own output under the shared top-level "steps" key
     * (steps.{branch id}), so comparing branches at the top level would
     * flag a conflict on every fan-out of two or more agent branches.
     * Branch step ids are disjoint by construction, making the per-id
     * union conflict-free unless a branch writes another step's key.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, array<string, mixed>>  $branchStates
     * @param  array<string, mixed>  $merged
     * @return array<string, mixed>
     */
    protected function mergeStepOutputs(
        ParallelStepDefinition $step,
        array $input,
        array $branchStates,
        array $merged,
    ): array {
        $writtenBy = [];

        foreach ($branchStates as $branchId => $branchState) {
            $steps = $branchState['steps'] ?? null;

            if (! is_array($steps)) {
                continue;
            }

            foreach ($steps as $stepId => $output) {
                if (array_key_exists($stepId, $input['steps'] ?? []) && $input['steps'][$stepId] === $output) {
                    continue; // unchanged from the snapshot
                }

                if (isset($writtenBy[$stepId]) && ($merged['steps'][$stepId] ?? null) !== $output) {
                    throw new StateMergeConflictException(
                        "Parallel step [{$step->id}]: branches [{$writtenBy[$stepId]}] and [{$branchId}] both wrote ".
                        "conflicting values to state key [steps.{$stepId}]. Provide a merge closure to resolve this."
                    );
                }

                $merged['steps'][$stepId] = $output;
                $writtenBy[$stepId] = $branchId;
            }
        }

        return $merged;
    }
}
