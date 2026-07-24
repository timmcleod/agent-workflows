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
     */
    public function merge(ParallelStepDefinition $step, array $input, array $branchStates): WorkflowState
    {
        if ($step->merge !== null) {
            $merged = ($step->merge)($branchStates, $input);

            return $merged instanceof WorkflowState ? $merged : WorkflowState::make($merged);
        }

        $merged = $input;
        $writtenBy = [];

        foreach ($branchStates as $branchId => $branchState) {
            foreach ($branchState as $key => $value) {
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

        return WorkflowState::make($merged);
    }
}
