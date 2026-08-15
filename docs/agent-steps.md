# Agent Steps

- [Introduction](#introduction)
- [Prompts](#prompts)
- [Retrieving Step Output](#retrieving-step-output)
- [Tools](#tools)
  - [Worker Timeouts](#worker-timeouts)
  - [Retries and Side Effects](#retries-and-side-effects)
  - [Tool Approvals](#tool-approvals)

## Introduction

Any `laravel/ai` agent may be used as a workflow step. Agents remain plain SDK classes — no package-specific interfaces or base classes are required:

```php
// In ContractReview::build():
return $workflow
    ->step(ExtractClausesAgent::class, fn (WorkflowState $state) => 'Extract the key clauses: '.$state->get('document.text'))
    ->step(RiskAnalysisAgent::class, fn (WorkflowState $state) => 'Assess the risk of: '.$state->get('steps.ExtractClausesAgent.text'));
```

Because prompts are defined on the step rather than the agent, the same agent may be asked different things in different workflows.

## Prompts

An agent step resolves its prompt through a ladder; the first rung that produces a string wins.

**A string on the step.** The prompt is the step's second argument. At its simplest, it is a plain string:

```php
->step(SummarizeAgent::class, 'Summarize the standard weekly report.')
```

Most prompts need the run's data, so string prompts may carry `{{ placeholder }}` templates, resolved against the workflow state when the step executes:

```php
->step(ExtractClausesAgent::class, 'Extract the key clauses: {{ contract }}')
->step(RiskAnalysisAgent::class, 'Assess the risk of: {{ output:ExtractClausesAgent }}')
->step(DeployAgent::class, 'The risk score is {{ output:RiskAnalysisAgent.riskScore }}. Proceed accordingly.')
```

Placeholders resolve dot paths into the state bag (`{{ contract }}`, `{{ document.title }}`, resume payloads, delivered event data), and the `output:` form addresses a prior step the way `$state->output()` does: bare `{{ output:StepId }}` is the step's text (or its whole structured output when the agent is structured), and `{{ output:StepId.path }}` reaches into the structured output. Booleans render as `true`/`false`; arrays and objects JSON-encode.

Two deliberate rules keep templates safe. An unresolvable placeholder **fails the step** with a `MissingWorkflowPromptException` naming it, rather than quietly prompting the agent with a hole. And there is **no escape syntax**: `{{` cannot occur inside valid JSON, so prompts containing JSON examples pass through untouched, and the rare prompt that needs a literal `{{` should use a closure. Only definition-authored strings are interpolated; closure results and the state `prompt` fallback never are, so runtime data can never smuggle placeholders into a prompt.

A prompt may also be a closure receiving the state, for logic that outgrows a template:

```php
->step(RiskAnalysisAgent::class, fn (WorkflowState $state) => 'Assess the risk of: '.$state->get('steps.ExtractClausesAgent.text'))
```

Note the trade: string templates hash verbatim into the [definition fingerprint](defining-workflows.md#definition-drift), so editing one is drift-visible; closures hash as an opaque `(closure)`.

The named form `prompt:` works everywhere the positional form does, and is how a prompt combines with other named arguments:

```php
->step(RiskAnalysisAgent::class, prompt: 'Assess the risk.', as: 'risk')
```

**Optionally, a conventional prompt method.** When a step defines no prompt, the workflow class is checked for a method named `{camelStepId}Prompt`, which receives the state and returns the prompt. Entirely opt-in: nothing changes for workflows that pass prompts on their steps. It earns its keep when prompts grow long, keeping `build` a skimmable table of contents while the prose lives below it:

```php
return $workflow->step(RiskAnalysisAgent::class);

// Bound automatically: camel of the step id "RiskAnalysisAgent" + "Prompt".
protected function riskAnalysisAgentPrompt(WorkflowState $state): string
{
    return 'Assess the risk of: '.$state->output(ExtractClausesAgent::class)?->text();
}
```

An aliased step looks for its alias: `->step(RiskAnalysisAgent::class, as: 'risk')` binds `riskPrompt()`. Prompt methods should be pure functions of the state they receive. An explicit `prompt:` always wins over a matching method, and ids that cannot be method names (`when:3`, a deduped `SummarizeAgent:2`) simply never match.

> [!WARNING]
> Because the method is found by step id, renaming a step's `as:` alias also changes which prompt method binds: a behavior change, not just a cosmetic one. Adding a conventional method to a previously promptless step changes the definition hash, so in-flight runs notice under strict [definition drift](defining-workflows.md#definition-drift), exactly as adding an explicit prompt would.

**The state's `prompt` key.** If neither the step nor the workflow class provides a prompt, the agent is prompted with the value of the state's `prompt` key. This is convenient for chat-shaped workflows where the run's input is the prompt. If no prompt can be resolved at all, the step fails with a `MissingWorkflowPromptException`.

Agent targets in other step types resolve prompts the same way: the `when` method accepts `thenPrompt` and `elsePrompt` arguments for its branches, the `evaluate` method accepts a `prompt` argument for its loop body, and both fall through to the conventional method for their step's id, which is also the only way to give a `parallel` branch its own prompt.

Steps also accept an optional `label` for live progress displays — see [defining workflows](defining-workflows.md#steps) and [`$run->progress()`](runs-and-observability.md#run-progress).

## Retrieving Step Output

After an agent step runs, its output is checkpointed into the workflow state under `steps.{step-id}`:

```php
$run->state['steps']['DraftReplyAgent']['text'];          // the response text
$run->state['steps']['RiskAnalysisAgent']['structured'];  // structured output
```

Agents that declare an output schema checkpoint only their `structured` output, since their text form is the same JSON again. All other agents checkpoint `text`.

Within steps, prompts, and conditions, you may prefer the `output` method over hand-written state paths — see [workflow state](workflow-state.md):

```php
$state->output(RiskAnalysisAgent::class)?->structured('riskScore');
```

Token usage from every agent response is recorded on the step's row in the run's audit log.

## Tools

An agent step is one full agentic turn, not a single LLM call. If the agent has tools, the SDK's tool loop runs to completion inside the step: the model may call a tool, read the result, and continue calling tools until it has an answer, capped by the agent's `#[MaxSteps]` attribute. The workflow checkpoints the finished result — so a single `->step(ResearchAgent::class)` may look up an order, query a knowledge base, and draft a response, all in one step.

The entire turn executes inside one queued job, which has three practical consequences.

### Worker Timeouts

A tool-heavy agent makes several LLM calls in one job. You should run workers with a `--timeout` (and a matching `retry_after`) sized to the whole turn, not to a single call. The [operations guide](operations.md) covers sizing in detail.

### Retries and Side Effects

The step is the retry unit. A failure at tool-round three retries the entire step from round one; there is no mid-loop checkpoint.

> [!WARNING]
> Because a retried step re-runs its whole tool loop, tools may execute more than once for the same logical work. You should keep tools idempotent, or move side-effecting work into its own callback step after the agent.

### Tool Approvals

A tool that [requires approval](human-in-the-loop.md#tool-approvals) pauses the agent mid-loop. The package converts the pause into a workflow interrupt and parks the run; when `resume` is called with the approval decisions, the loop continues from where it paused — not from round one. Approval pauses are supported in sequential steps and in `evaluate` loop bodies.

> [!WARNING]
> Approval-gated agents are not supported inside `parallel` branches. A branch that pauses on approvals fails the run with an explicit error. Keep approval-gated agents in sequential steps before or after the fan-out.
