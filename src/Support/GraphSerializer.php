<?php

namespace TimMcLeod\AgentWorkflows\Support;

use Closure;
use Illuminate\Support\Str;
use TimMcLeod\AgentWorkflows\AwaitEventStepDefinition;
use TimMcLeod\AgentWorkflows\AwaitHumanStepDefinition;
use TimMcLeod\AgentWorkflows\ConditionStepDefinition;
use TimMcLeod\AgentWorkflows\DebateRoundDefinition;
use TimMcLeod\AgentWorkflows\EvaluateStepDefinition;
use TimMcLeod\AgentWorkflows\ParallelStepDefinition;
use TimMcLeod\AgentWorkflows\StepDefinition;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;

/**
 * Flattens a definition into a renderable graph: rows of nodes plus labelled
 * edges. A workflow is a linear spine with structured branches, so layout is
 * deterministic — one row per spine step; branch children share the row that
 * follows their parent. Consumed by dashboards and diagram tooling via
 * WorkflowDefinition::toGraph().
 */
class GraphSerializer
{
    /**
     * @return array{name: string, hash: string, rows: array<int, array<int, string>>, nodes: array<string, array<string, mixed>>, edges: array<int, array{from: string, to: string, label: string|null}>}
     */
    public function serialize(WorkflowDefinition $definition): array
    {
        $rows = [];
        $nodes = [];
        $edges = [];

        // Nodes whose outgoing edge attaches to the next spine node,
        // each as [id, edge-label].
        /** @var array<int, array{string, string|null}> $exits */
        $exits = [];

        $connect = function (string $to) use (&$edges, &$exits): void {
            foreach ($exits as [$from, $label]) {
                $edges[] = ['from' => $from, 'to' => $to, 'label' => $label];
            }

            $exits = [];
        };

        foreach ($definition->steps() as $step) {
            $nodes[$step->id] = $this->node($step);
            $connect($step->id);
            $rows[] = [$step->id];

            if (! $step instanceof ConditionStepDefinition && ! $step instanceof ParallelStepDefinition) {
                $exits = [[$step->id, null]];

                continue;
            }

            $children = [];

            foreach ($step->children() as $child) {
                $nodes[$child->id] = $this->node($child, branchOf: $step->id);
                $children[] = $child->id;

                $edges[] = [
                    'from' => $step->id,
                    'to' => $child->id,
                    'label' => $step instanceof ConditionStepDefinition
                        ? ($child === $step->whenTrue ? 'yes' : 'no')
                        : null,
                ];
            }

            $rows[] = $children;

            $exits = array_map(fn (string $id) => [$id, null], $children);

            // A condition without an else-branch flows straight past when false.
            if ($step instanceof ConditionStepDefinition && $step->whenFalse === null) {
                $exits[] = [$step->id, 'no'];
            }
        }

        return [
            'name' => $definition->name,
            'hash' => $definition->hash(),
            'rows' => $rows,
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function node(StepDefinition $step, ?string $branchOf = null): array
    {
        [$target, $detail] = match (true) {
            $step instanceof ConditionStepDefinition => [null, 'branches on run state'],
            $step instanceof ParallelStepDefinition => [null, count($step->branches).' branches · '.$step->mode],
            $step instanceof EvaluateStepDefinition && $step->body instanceof DebateRoundDefinition => [$step->body->judge, 'debate · '.count($step->body->debaters).' voices · max '.$step->maxIterations.' rounds'],
            $step instanceof EvaluateStepDefinition => [$step->body->target, 'loop until satisfied · max '.$step->maxIterations.'×'],
            $step instanceof AwaitHumanStepDefinition => [null, $step->reason ?? 'Waiting for a human'],
            $step instanceof AwaitEventStepDefinition => [null, 'event: '.$step->event],
            default => [$step->target, $this->promptDetail($step)],
        };

        return array_filter([
            'id' => $step->id,
            'type' => $step->type->value,
            'label' => $step->id,
            'target' => $target !== null ? class_basename($target) : null,
            'detail' => $detail,
            'branchOf' => $branchOf,
            'schema' => $step instanceof AwaitHumanStepDefinition ? $step->schema : null,
        ], fn (mixed $value) => $value !== null);
    }

    protected function promptDetail(StepDefinition $step): ?string
    {
        return match (true) {
            is_string($step->prompt) => Str::limit($step->prompt, 60),
            $step->prompt instanceof Closure => 'dynamic prompt',
            default => null,
        };
    }
}
