<?php

namespace TimMcLeod\AgentWorkflows;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use TimMcLeod\AgentWorkflows\Enums\StepType;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;

class WorkflowDefinition
{
    /** @var array<int, StepDefinition> */
    protected array $steps = [];

    /** @var array<int, string> */
    protected array $reservedIds = [];

    /**
     * @param  class-string<WorkflowState>  $stateClass
     * @param  Closure(string): ?Closure  $promptResolver  given a conventional
     *                                                     method name, returns a prompt closure or null;
     *                                                     supplied by Workflow::definition() so protected
     *                                                     prompt methods bind from inside their own class
     */
    public function __construct(
        public readonly string $name,
        public readonly string $stateClass = WorkflowState::class,
        protected readonly ?Closure $promptResolver = null,
    ) {
        if (! is_a($stateClass, WorkflowState::class, true)) {
            throw new InvalidArgumentException(
                "A workflow state class must be or extend WorkflowState; [{$stateClass}] given."
            );
        }
    }

    /**
     * Hydrate a state instance of this workflow's declared state class.
     *
     * @param  array<string, mixed>  $data
     */
    public function makeState(array $data = []): WorkflowState
    {
        return ($this->stateClass)::make($data);
    }

    /**
     * Append a step that runs a unit of work. Agent classes become agent
     * steps; any other invokable class becomes a callback step. Steps run
     * in the order they are added.
     *
     * Agent steps resolve their prompt in order: $prompt (a plain string,
     * or a closure receiving the workflow state), then a workflow-class
     * method named {camel(stepId)}Prompt, then the state's "prompt" key.
     *
     * @param  class-string  $target
     * @param  Closure(WorkflowState): string|string|null  $prompt
     */
    public function step(string $target, Closure|string|null $prompt = null, ?string $as = null, ?string $label = null): static
    {
        $this->steps[] = $this->makeStep($target, $prompt, $as, $label);

        return $this;
    }

    /**
     * Branch at runtime: when the condition holds, run $then, otherwise run
     * $else (or skip straight to the next step when $else is omitted). The
     * workflow continues sequentially after whichever branch ran. Agent
     * branch targets take their prompts from $thenPrompt / $elsePrompt.
     *
     * @param  Closure(WorkflowState): bool  $condition
     * @param  class-string  $then
     * @param  class-string|null  $else
     * @param  Closure(WorkflowState): string|string|null  $thenPrompt
     * @param  Closure(WorkflowState): string|string|null  $elsePrompt
     */
    public function when(
        Closure $condition,
        string $then,
        ?string $else = null,
        ?string $as = null,
        Closure|string|null $thenPrompt = null,
        Closure|string|null $elsePrompt = null,
        ?string $label = null,
    ): static {
        $whenTrue = $this->makeStep($then, $thenPrompt);
        $whenFalse = $else !== null ? $this->makeStep($else, $elsePrompt) : null;

        $this->steps[] = new ConditionStepDefinition(
            $this->stepId($as ?? 'when:'.(count($this->steps) + 1), explicit: $as !== null),
            $condition,
            $whenTrue,
            $whenFalse,
            $label,
        );

        return $this;
    }

    /**
     * Fan out into concurrent branches, each starting from the same state
     * snapshot, then merge the branch states and continue.
     *
     * Modes: "batch" (default) runs branches as a queued Bus::batch — the
     * durable option; "sync" runs them in-process via Concurrency::run().
     *
     * @param  array<int|string, class-string>  $targets  string keys become step aliases
     * @param  Closure(array<string, array<string, mixed>>, array<string, mixed>): (WorkflowState|array<string, mixed>)|null  $merge
     */
    public function parallel(array $targets, ?Closure $merge = null, string $mode = 'batch', ?string $as = null, ?string $label = null): static
    {
        if (! in_array($mode, ['batch', 'sync'], true)) {
            throw new InvalidArgumentException("Parallel mode must be \"batch\" or \"sync\", [{$mode}] given.");
        }

        if ($targets === []) {
            throw new InvalidArgumentException('A parallel step needs at least one branch.');
        }

        $branches = [];

        foreach ($targets as $key => $target) {
            $branches[] = $this->makeStep($target, null, is_string($key) ? $key : null);
        }

        $this->steps[] = new ParallelStepDefinition(
            $this->stepId($as ?? 'parallel:'.(count($this->steps) + 1), explicit: $as !== null),
            $branches,
            $merge,
            $mode,
            $label,
        );

        return $this;
    }

    /**
     * Evaluator-optimizer loop: run the target repeatedly until the predicate
     * holds (or maxIterations is reached), checkpointing every iteration. An
     * agent target takes its per-iteration prompt from $prompt.
     *
     * @param  class-string  $target
     * @param  Closure(WorkflowState): bool  $until
     * @param  Closure(WorkflowState): string|string|null  $prompt
     */
    public function evaluate(
        string $target,
        Closure $until,
        int $maxIterations = 3,
        ?string $as = null,
        Closure|string|null $prompt = null,
        ?string $label = null,
    ): static {
        if ($maxIterations < 1) {
            throw new InvalidArgumentException('maxIterations must be at least 1.');
        }

        // The default id is the bare class basename — the same id a plain
        // step() would get — so output(Target::class) addresses the loop's
        // checkpoints exactly like any other step's.
        $id = $this->stepId($as ?? $target, explicit: $as !== null);

        // The body deliberately shares the evaluate step's id (see EvaluateStepDefinition).
        $type = $this->typeFor($target);

        $body = new StepDefinition($id, $type, $target, $this->resolvePrompt($prompt, $type, $id));

        $this->steps[] = new EvaluateStepDefinition($id, $body, $until, $maxIterations, $label);

        return $this;
    }

    /**
     * Multi-agent debate: two or more debater agents argue the topic in
     * rounds — openings first, rebuttals after — a structured-output judge
     * rules on the transcript after each round, and the loop stops on
     * consensus (or a custom until: predicate) or at the round cap.
     *
     * Sugar over evaluate(): each round is one iteration, one checkpoint,
     * one audit row, and the graph stays static. The judge's verdict lands
     * under "steps.{as}.judge", the transcript under "steps.{as}.transcript"
     * (read it with Support\Transcript::in()).
     *
     * $as is required: debates are long-lived and expensive, and a
     * positional default id would silently renumber (moving state paths and
     * the drift hash) when an earlier step is inserted.
     *
     * Cost grows quadratically with $rounds — every debater is re-prompted
     * with the growing transcript each round. $transcriptWindow bounds the
     * debaters' prompts to the last N rounds; the judge always sees the
     * full transcript. Size the queue's retry_after and the sweep's
     * stale_after above the worst-case round duration (roughly
     * debaters + 1 sequential agent calls).
     *
     * @param  array<int|string, class-string>  $debaters  string keys become speaker aliases
     * @param  class-string  $judge  agent with structured output; the default predicate reads its `consensus` bool
     * @param  int  $rounds  the round cap; hitting it is an outcome (satisfied=false), not a failure
     * @param  Closure(WorkflowState): string|string|null  $topic  defaults to the state's "prompt" key
     * @param  Closure(WorkflowState): bool|null  $until  replaces the default `judge.consensus === true`
     * @param  ?int  $transcriptWindow  render only the last N rounds in debater prompts
     * @param  Closure(WorkflowState, Support\Transcript, string): string|null  $openingPrompt
     * @param  Closure(WorkflowState, Support\Transcript, string): string|null  $rebuttalPrompt
     * @param  Closure(WorkflowState, Support\Transcript): string|null  $judgePrompt
     */
    public function debate(
        array $debaters,
        string $judge,
        string $as,
        int $rounds = 3,
        Closure|string|null $topic = null,
        ?Closure $until = null,
        ?int $transcriptWindow = null,
        ?Closure $openingPrompt = null,
        ?Closure $rebuttalPrompt = null,
        ?Closure $judgePrompt = null,
        ?string $label = null,
    ): static {
        if (count($debaters) < 2) {
            throw new InvalidArgumentException(
                "Workflow [{$this->name}] debate needs at least two debaters."
            );
        }

        $speakers = [];

        foreach ($debaters as $alias => $class) {
            if ($this->typeFor($class) !== StepType::Agent) {
                throw new InvalidArgumentException(
                    "Workflow [{$this->name}] debate participant [{$class}] must be an agent class."
                );
            }

            $speaker = is_string($alias) ? $alias : class_basename($class);

            if (isset($speakers[$speaker])) {
                throw new InvalidArgumentException(
                    "Workflow [{$this->name}] debate has two speakers named [{$speaker}]; ".
                    'use string keys to alias debaters of the same class.'
                );
            }

            $speakers[$speaker] = $class;
        }

        if ($this->typeFor($judge) !== StepType::Agent || ! is_a($judge, HasStructuredOutput::class, true)) {
            throw new InvalidArgumentException(
                "Workflow [{$this->name}] debate judge [{$judge}] must be an agent with structured ".
                'output — the default predicate reads a `consensus` boolean from its verdict.'
            );
        }

        if ($rounds < 1) {
            throw new InvalidArgumentException('A debate needs at least one round.');
        }

        if ($transcriptWindow !== null && $transcriptWindow < 1) {
            throw new InvalidArgumentException(
                'transcriptWindow must be at least 1, or null for the full transcript.'
            );
        }

        $id = $this->stepId($as, explicit: true);

        $this->steps[] = new EvaluateStepDefinition(
            $id,
            new DebateRoundDefinition(
                $id,
                $speakers,
                $judge,
                $topic,
                $transcriptWindow,
                defaultUntil: $until === null,
                openingPrompt: $openingPrompt,
                rebuttalPrompt: $rebuttalPrompt,
                judgePrompt: $judgePrompt,
            ),
            $until ?? fn (WorkflowState $state): bool => $state->get('steps.'.$id.'.judge.consensus') === true,
            $rounds,
            $label,
        );

        return $this;
    }

    /**
     * Pause the run until a human resumes it. The run is persisted as
     * awaiting_human with the reason and an optional response schema
     * (Laravel validation rules); resume() validates the human's payload
     * against the schema and merges it into state.
     *
     * With a timeout, the scheduled sweeper acts on runs still waiting when
     * it expires: given a timeoutResponse, the run resumes with that payload
     * (an auto-decision, validated like any other); without one, the run
     * fails at this step — retry() re-arms the gate with a fresh deadline.
     *
     * @param  array<string, mixed>|null  $schema
     * @param  int|\DateInterval|null  $timeout  seconds, or any DateInterval
     * @param  array<string, mixed>|null  $timeoutResponse
     */
    public function awaitHuman(
        ?string $reason = null,
        ?array $schema = null,
        int|\DateInterval|null $timeout = null,
        ?array $timeoutResponse = null,
        ?string $as = null,
        ?string $label = null,
    ): static {
        if ($timeout instanceof \DateInterval) {
            $timeout = (int) CarbonInterval::instance($timeout)->totalSeconds;
        }

        if ($timeout !== null && $timeout < 1) {
            throw new InvalidArgumentException('An awaitHuman timeout must be at least one second.');
        }

        if ($timeoutResponse !== null && $timeout === null) {
            throw new InvalidArgumentException('An awaitHuman timeoutResponse requires a timeout.');
        }

        $this->steps[] = new AwaitHumanStepDefinition(
            $this->stepId($as ?? 'await-human:'.(count($this->steps) + 1), explicit: $as !== null),
            $reason,
            $schema !== null ? $this->normalizeSchema($schema) : null,
            $timeout,
            $timeoutResponse,
            $label,
        );

        return $this;
    }

    /**
     * Normalize a response schema so it survives JSON persistence. The
     * schema is stored on the interrupt row and re-read at resume time, so
     * rule objects must be reduced to their string form now — json_encode
     * silently turns them into {} (an empty, always-passing constraint).
     * Stringable rules (Rule::in(...), 'exists:...') cast losslessly;
     * closures and non-Stringable rule objects (Rule::enum, Password) have
     * no string form and are rejected outright.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function normalizeSchema(array $schema): array
    {
        foreach ($schema as $field => $rules) {
            $schema[$field] = is_array($rules)
                ? array_map(fn (mixed $rule) => $this->normalizeRule($rule, $field), $rules)
                : $this->normalizeRule($rules, $field);
        }

        return $schema;
    }

    protected function normalizeRule(mixed $rule, string $field): mixed
    {
        if (is_string($rule) || is_scalar($rule) || $rule === null) {
            return $rule;
        }

        if (is_object($rule) && method_exists($rule, '__toString')) {
            return (string) $rule;
        }

        $type = is_object($rule) ? get_class($rule) : get_debug_type($rule);

        throw new InvalidArgumentException(
            "Schema rule for [{$field}] is a [{$type}], which cannot survive JSON persistence. ".
            'Use string rules or Stringable rule objects (Rule::in(...), not Rule::enum(...) or closures).'
        );
    }

    /**
     * Pause the run until a named application event is delivered via
     * $run->deliverEvent($event, $payload). With a schema (Laravel
     * validation rules), the delivered payload is validated before it
     * merges into state.
     *
     * @param  array<string, mixed>|null  $schema
     */
    public function awaitEvent(string $event, ?string $as = null, ?array $schema = null, ?string $label = null): static
    {
        $this->steps[] = new AwaitEventStepDefinition(
            $this->stepId($as ?? 'await-event:'.$event, explicit: $as !== null),
            $event,
            $schema !== null ? $this->normalizeSchema($schema) : null,
            $label,
        );

        return $this;
    }

    /**
     * @return array<int, StepDefinition>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function findStep(string $id): StepDefinition
    {
        foreach ($this->allSteps() as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        throw new WorkflowException("Workflow [{$this->name}] has no step [{$id}].");
    }

    public function hasStep(string $id): bool
    {
        foreach ($this->allSteps() as $step) {
            if ($step->id === $id) {
                return true;
            }
        }

        return false;
    }

    public function firstStep(): StepDefinition
    {
        if ($this->steps === []) {
            throw new WorkflowException("Workflow [{$this->name}] has no steps.");
        }

        return $this->steps[0];
    }

    /**
     * The step that follows the given step, or null if it is the last. For a
     * step nested inside a condition, the successor is whatever follows the
     * condition itself.
     */
    public function after(string $id): ?StepDefinition
    {
        foreach ($this->steps as $index => $step) {
            if ($step->id === $id) {
                return $this->steps[$index + 1] ?? null;
            }
        }

        foreach ($this->steps as $step) {
            foreach ($step->children() as $child) {
                if ($child->id === $id) {
                    return $this->after($step->id);
                }
            }
        }

        throw new WorkflowException("Workflow [{$this->name}] has no step [{$id}].");
    }

    /**
     * A renderable graph of the definition: rows of nodes plus labelled
     * edges, for dashboards and diagram tooling.
     *
     * @return array{name: string, hash: string, rows: array<int, array<int, string>>, nodes: array<string, array<string, mixed>>, edges: array<int, array{from: string, to: string, label: string|null}>}
     */
    public function toGraph(): array
    {
        return (new Support\GraphSerializer)->serialize($this);
    }

    /**
     * A stable hash of the definition, stored on each run at start time so
     * definition drift across deploys can be detected on resume. Closure
     * bodies (conditions, predicates, merge strategies) are not hashed —
     * only the step structure is.
     */
    public function hash(): string
    {
        $fingerprint = [
            'name' => $this->name,
            'steps' => array_map(fn (StepDefinition $step) => $step->fingerprint(), $this->steps),
        ];

        return hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  class-string  $target
     * @param  Closure(WorkflowState): string|string|null  $prompt
     */
    protected function makeStep(string $target, Closure|string|null $prompt = null, ?string $as = null, ?string $label = null): StepDefinition
    {
        $id = $this->stepId($as ?? $target, explicit: $as !== null);
        $type = $this->typeFor($target);

        return new StepDefinition($id, $type, $target, $this->resolvePrompt($prompt, $type, $id), $label);
    }

    /**
     * The definition-time rungs of the prompt ladder: an explicit prompt
     * wins; otherwise an agent step binds the workflow class's conventional
     * {camel(stepId)}Prompt method when one exists. Ids that cannot be
     * method names (when:3, TransformStep:2) simply never match. The
     * remaining rungs (the state's "prompt" key, then failure) live in the
     * executor, which only ever sees the compiled StepDefinition.
     *
     * @param  Closure(WorkflowState): string|string|null  $prompt
     */
    protected function resolvePrompt(Closure|string|null $prompt, StepType $type, string $id): Closure|string|null
    {
        if ($prompt !== null || $type !== StepType::Agent || $this->promptResolver === null) {
            return $prompt;
        }

        return ($this->promptResolver)(Str::camel($id).'Prompt');
    }

    /**
     * @param  class-string  $target
     */
    protected function typeFor(string $target): StepType
    {
        // Fail at definition time, not deep inside a queue worker: any
        // non-Agent string would otherwise become a "callback step" —
        // including typos.
        if (! class_exists($target)) {
            throw new InvalidArgumentException(
                "Workflow [{$this->name}] step target [{$target}] is not a class. ".
                'Step targets are agent classes or invokable classes.'
            );
        }

        return is_a($target, Agent::class, true) ? StepType::Agent : StepType::Callback;
    }

    /**
     * Every step in the definition, including nested branch steps.
     *
     * @return array<int, StepDefinition>
     */
    protected function allSteps(): array
    {
        $all = [];

        foreach ($this->steps as $step) {
            $all[] = $step;

            foreach ($step->children() as $child) {
                $all[] = $child;
            }
        }

        return $all;
    }

    /**
     * Derive a unique, readable step id from the target class (or explicit
     * alias), reserving it so steps built before being appended (branch
     * steps) still collide-check against each other.
     *
     * Derived ids are deduped with a numeric suffix (the same class used
     * twice); an explicit alias that collides throws — silently renaming
     * it would point audit rows, state paths, and output() lookups at the
     * wrong step.
     */
    protected function stepId(string $base, bool $explicit = false): string
    {
        $id = class_exists($base) ? class_basename($base) : $base;

        if ($explicit && in_array($id, $this->reservedIds, true)) {
            throw new InvalidArgumentException(
                "Workflow [{$this->name}] already has a step [{$id}]; explicit step aliases must be unique."
            );
        }

        $candidate = $id;
        $suffix = 2;

        while (in_array($candidate, $this->reservedIds, true)) {
            $candidate = $id.':'.$suffix++;
        }

        $this->reservedIds[] = $candidate;

        return $candidate;
    }
}
