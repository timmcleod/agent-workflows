# Changelog

All notable changes to `timmcleod/agent-workflows` are documented here. During 0.x, minor versions may contain breaking changes; each entry flags them.

## v0.8.0 — 2026-07-28

**Typed workflow state.** Additive, no breaking changes. See [docs/typed-state.md](docs/typed-state.md).

- A workflow may override `stateClass()` with a `WorkflowState` subclass; the engine hydrates it everywhere user code receives state — steps, prompt closures, conditions, predicates, and continuations after `resume()`/`deliverEvent()`/`retry()`. Purely a lens: storage, checkpoints, and merge semantics are unchanged, and the state class is excluded from the definition hash so adopting one never strands in-flight runs.
- `WorkflowState::output()` addresses a step's checkpointed output by class or step id — `output(RiskAnalysisAgent::class)?->structured('riskScore')` instead of `get('steps.RiskAnalysisAgent.structured.riskScore')` — returning `null` before the step has checkpointed.

## v0.7.0 — 2026-07-28

- `WorkflowDefinition::toGraph()`: serializes a definition into a renderable graph (rows of typed nodes, labelled edges, display metadata, definition hash) for dashboards and diagram tooling. First consumer: [`timmcleod/agent-workflows-ui`](https://github.com/timmcleod/agent-workflows-ui).
- Declared `created_at`/`updated_at` on the `WorkflowRun` and `WorkflowInterrupt` docblocks for static analysis in consuming apps.

## v0.6.0 — 2026-07-25

**Breaking:** the conversation-handoffs feature is removed — the package is now purely about durable workflow processes. Removed: `HasHandoffs`/`HasHandoffTools`, the synthetic `transfer_to_*` tools, `AgentWorkflow::resolveAgentFor()`/`transferConversation()`, the `ConversationTransferred` event, and the `agent_conversation_owners` table. If you used handoffs, stay on v0.5.1 or vendor the classes from that tag.

## v0.5.1 — 2026-07-25

- Structured agents no longer checkpoint their response twice: `steps.{id}.text` is omitted when `steps.{id}.structured` holds the same data. Read from `structured` for schema agents; plain agents keep `text`.

## v0.5.0 — 2026-07-25

Correctness and liveness hardening — the durability claims made provable. No definition API changes.

- Idempotent step claims: locked-transaction claims plus a unique `(run_id, step_id, attempt)` index; duplicate queue deliveries no-op. **Existing installs must re-run the migration to pick up the index.**
- Atomic conditional transitions for cursor advances, failures, resumes, retries, and event deliveries; concurrent `resume()` calls yield exactly one resumption.
- Documented semantics: step bodies at-least-once; checkpoints and cursor advances exactly-once.
- `php artisan agent-workflows:sweep` recovers runs stranded by hard-killed workers (config `sweep.stale_after`/`sweep.action`).
- `$run->cancel()` from any non-terminal state, with the `WorkflowCancelled` event and `assertCancelled()`.

## v0.4.0 — 2026-07-24

**Breaking:** `AgentWorkflow::define()` is removed; every workflow is a class (`php artisan make:agent-workflow`) registered in the config `workflows` array, which loads on every process at boot — so queue workers always know your definitions.

## v0.3.0 — 2026-07-24

**Breaking:** `HasWorkflowPrompt` is removed; prompts are defined on the step, where the workflow context lives: `step($target, prompt:)`, `when(..., thenPrompt:, elsePrompt:)`, `evaluate(..., prompt:)`. String prompts count toward the definition hash.

## v0.2.0 — 2026-07-24

**Breaking:** the builder's `start()`/`then()` are replaced by a single `step()`; `AgentWorkflow::start()` (creating a run) is unchanged. The internal `WorkflowDefinition::step($id)` lookup is now `findStep($id)`.

## v0.1.0 — 2026-07-24

Initial pre-release: sequential/conditional/parallel/evaluator steps with a checkpoint after every step, retry-from-failed-step, `awaitHuman()`/`awaitEvent()` interrupts with schema-validated resume, SDK tool-approval pauses surfaced as interrupts, lifecycle events, per-step usage audit trail, and `AgentWorkflow::fake()` test assertions.
