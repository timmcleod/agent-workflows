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
    ->step(ExtractClausesAgent::class,
        prompt: fn (WorkflowState $state) => 'Extract the key clauses: '.$state->get('document.text'))
    ->step(RiskAnalysisAgent::class,
        prompt: fn (WorkflowState $state) => 'Assess the risk of: '.$state->get('steps.ExtractClausesAgent.text'));
```

Because prompts are defined on the step rather than the agent, the same agent may be asked different things in different workflows.

## Prompts

At its simplest, a step's prompt is a plain string:

```php
->step(SummarizeAgent::class, prompt: 'Summarize the standard weekly report.')
```

Most prompts need the run's data, so a prompt may also be a closure that receives the workflow's current state:

```php
->step(RiskAnalysisAgent::class,
    prompt: fn (WorkflowState $state) => 'Assess the risk of: '.$state->get('steps.ExtractClausesAgent.text'))
```

When prompts grow long, a first-class callable referencing a method on your workflow class keeps your `build` method skimmable:

```php
->step(RiskAnalysisAgent::class, prompt: $this->riskPrompt(...))
```

If a step does not define a prompt, the agent will be prompted with the value of the state's `prompt` key. This is convenient for chat-shaped workflows where the run's input is the prompt. If no prompt can be resolved from the step or the state, the step fails with a `MissingWorkflowPromptException`.

Agent targets in other step types accept prompts as well: the `when` method accepts `thenPrompt` and `elsePrompt` arguments for its branches, and the `evaluate` method accepts a `prompt` argument for its loop body.

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
