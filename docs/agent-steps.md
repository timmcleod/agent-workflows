# Agent steps

Any `laravel/ai` agent class can be a step — agents stay plain SDK classes with no package-specific code on them. The step's prompt is defined where the step is, so the same agent can be asked different things in different workflows:

```php
// In ContractReview::build():
return $workflow
    ->step(ExtractClausesAgent::class,
        prompt: fn (WorkflowState $s) => 'Extract the key clauses: '.$s->get('document.text'))
    ->step(RiskAnalysisAgent::class,
        prompt: fn (WorkflowState $s) => 'Assess the risk of: '.$s->get('steps.ExtractClausesAgent.text'));
```

`prompt:` takes any closure receiving the state — an inline `fn` as above, or a first-class callable to a method on your workflow class (`prompt: $this->riskPrompt(...)`), which keeps `build()` skimmable when prompts run long. A plain string works for static prompts. If a step has no `prompt:`, the state's `prompt` key is used — handy for chat-shaped runs where the input *is* the prompt.

Agent targets in other step types carry prompts too: `when(..., thenPrompt:, elsePrompt:)` for branches, and `evaluate(..., prompt:)` for the loop body.

After the step runs, its output is checkpointed under `steps.{step id}`:

```php
$run->state['steps']['DraftReplyAgent']['text'];          // the response text
$run->state['steps']['RiskAnalysisAgent']['structured'];  // structured output, when the agent declares a schema
```

Agents with a schema checkpoint only `structured` (their text form is the same JSON again); everything else checkpoints `text`.

Token usage from every agent response is recorded on the step's audit row.

## Agents use their tools freely within a step

An agent step is one full agentic turn, not one LLM call. If the agent has tools, the SDK's tool loop runs to completion inside the step: the model calls a tool, reads the result, thinks, calls another, and keeps going until it has an answer (capped by `#[MaxSteps]` on the agent). The workflow checkpoints the finished result. So a single `->step(ResearchAgent::class)` can look up an order, query a knowledge base, and draft a response, all in one step.

Three things follow from "the loop lives inside one queued job":

- **Give workers room.** A tool-heavy agent makes several LLM calls in one job, so run workers with a `--timeout` (and matching `retry_after`) that covers the whole turn, not one call.
- **The step is the retry unit.** A failure at tool-round 3 retries the whole step from round 1. There is no mid-loop checkpoint, so keep tools idempotent, or pull side-effecting work into its own callback step after the agent.
- **Approval pauses are the exception.** A tool that [requires approval](human-in-the-loop.md#sdk-tool-approvals-become-workflow-interrupts) mid-loop parks the run; on `resume()` the loop continues from where it paused, not from round 1. This works for sequential steps and `evaluate()` bodies. **Inside `parallel()` branches, approval-gated agents are not supported** — a branch that pauses fails the run with an explicit error; keep approval-gated agents in sequential steps before or after the fan-out.

