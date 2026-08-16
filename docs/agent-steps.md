# Agent Steps

- [Introduction](#introduction)
- [Prompts](#prompts)
  - [Strings and Templates](#strings-and-templates)
  - [Closures](#closures)
  - [Conventional Prompt Methods](#conventional-prompt-methods)
  - [The State Fallback](#the-state-fallback)
- [Tools](#tools)
  - [Worker Timeouts](#worker-timeouts)
  - [Retries and Side Effects](#retries-and-side-effects)
  - [Tool Approvals](#tool-approvals)

## Introduction

Any `laravel/ai` agent may be used as a workflow step. Agents remain plain SDK classes, with no package-specific interfaces or base classes required. Because prompts are defined on the step rather than the agent, the same agent may be asked different things in different workflows.

An agent step's output is checkpointed into the workflow state under `steps.{step-id}` and read back with [`$state->output()`](workflow-state.md#retrieving-step-output). Its token usage and [per-call detail](runs-and-observability.md#per-call-audit) land on the step's audit row.

## Prompts

An agent step resolves its prompt through a ladder, and the first rung that produces a string wins: an explicit prompt on the step, then a conventional prompt method, then the state's `prompt` key. When every rung comes up empty, the step fails with a `MissingWorkflowPromptException` naming all three options.

### Strings and Templates

The prompt is the step's second argument. At its simplest, it is a plain string:

```php
->step(SummarizeAgent::class, 'Summarize the standard weekly report.')
```

Most prompts need the run's data, so string prompts may carry `{{ placeholder }}` templates, resolved against the workflow state when the step executes:

```php
->step(
    ExtractClausesAgent::class,
    'Extract the key clauses: {{ contract }}'
)
->step(
    RiskAnalysisAgent::class,
    'Assess the risk of: {{ output:ExtractClausesAgent }}'
)
->step(
    DeployAgent::class,
    'The risk score is {{ output:RiskAnalysisAgent.riskScore }}. Proceed accordingly.'
)
```

- Placeholders resolve dot paths into the state bag (`{{ contract }}`, `{{ document.title }}`, resume payloads, delivered event data). The `output:` form addresses a prior step like [`$state->output()`](workflow-state.md#retrieving-step-output): bare for the text, `.path` into structured output. Booleans render as `true`/`false`, and arrays are JSON-encoded.
- An unresolvable placeholder **fails the step** with a `MissingWorkflowPromptException` naming it, rather than quietly prompting with a hole. Fix the path, or supply the missing state.
- There is **no escape syntax**. Since `{{` cannot occur inside valid JSON, prompts containing JSON examples pass through untouched. The rare prompt needing a literal `{{` should use a closure. Only definition-authored strings interpolate, never closure results or runtime data.

The named form `prompt:` combines with other named arguments: `->step(RiskAnalysisAgent::class, prompt: 'Assess the risk.', as: 'risk')`.

### Closures

A prompt may be a closure receiving the state, for logic a template cannot express:

```php
->step(
    RiskAnalysisAgent::class,
    fn (WorkflowState $state) => 'Assess the risk of: '.($state->get('revised') ?? $state->get('draft'))
)
```

There is a trade to be aware of: string templates hash verbatim into the [definition fingerprint](defining-workflows.md#definition-drift), so editing one is drift-visible, while closures hash as an opaque `(closure)`.

### Conventional Prompt Methods

When a step defines no prompt, the workflow class is checked for a method named `{camelStepId}Prompt`. The method receives the state and returns the prompt. This convention earns its keep when prompts grow long, keeping `build` a skimmable table of contents:

```php
return $workflow->step(RiskAnalysisAgent::class);

// Bound automatically: camel of the step id "RiskAnalysisAgent" + "Prompt".
protected function riskAnalysisAgentPrompt(WorkflowState $state): string
{
    return 'Assess the risk of: '.$state->output(ExtractClausesAgent::class)?->text();
}
```

An aliased step looks for its alias, so `as: 'risk'` binds `riskPrompt()`. An explicit prompt always wins over a matching method, and ids that cannot be method names (`when:3`, `SummarizeAgent:2`) never match. Prompt methods should be pure functions of the state they receive.

> [!WARNING]
> The method is found by step id, so renaming an `as:` alias changes which method binds: a behavior change, not a cosmetic one. Adding a method to a previously promptless step changes the [definition hash](defining-workflows.md#definition-drift), exactly as adding an explicit prompt would.

### The State Fallback

With no step prompt and no matching method, the agent is prompted with the value of the state's `prompt` key. This is convenient for chat-shaped workflows where the run's input is the prompt.

The same ladder serves agent targets everywhere: `when()` branches (`thenPrompt`/`elsePrompt`), `evaluate()` bodies (`prompt:`), and `parallel()` branches ([`[class, prompt]` pairs](defining-workflows.md#parallel-steps)).

## Tools

An agent step is **one full agentic turn**, not a single LLM call. If the agent has tools, the SDK's tool loop runs to completion inside the step: the model may call a tool, read the result, and continue until it has an answer, capped by the agent's `#[MaxSteps]` attribute. A single `->step(ResearchAgent::class)` may look up an order, query a knowledge base, and draft a response.

The turn executes inside one queued job, with three practical consequences.

### Worker Timeouts

You should size worker `--timeout` and `retry_after` to the whole turn, not a single call. See [queue configuration](operations.md#queue-configuration).

### Retries and Side Effects

The step is the [retry unit](runs-and-observability.md#retry-semantics): a failure at tool-round three retries the whole turn from round one.

> [!WARNING]
> A retried step re-runs its whole tool loop, so tools may execute more than once for the same logical work. Keep tools idempotent, or move side-effecting work into its own callback step after the agent.

### Tool Approvals

A tool that requires approval pauses the agent mid-loop. The run parks, and on resume the loop continues from where it paused, not from round one. The full flow, including decisions and replay, lives in [Human in the Loop](human-in-the-loop.md#tool-approvals). Approval pauses work in sequential steps and `evaluate` bodies, [not in parallel branches](defining-workflows.md#parallel-steps).
