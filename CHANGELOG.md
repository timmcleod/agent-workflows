# Changelog

All notable changes to `timmcleod/agent-workflows` are documented here. During 0.x, minor versions may contain breaking changes; each entry flags them.

## Unreleased

Feature release: parallel branches carry their own prompts. No schema changes, no migration, nothing breaking.

**Added:**

- **Per-branch prompts in `parallel()` via `[class, prompt]` pairs.** The key names the branch, the value describes it, and all forms mix freely: `FinancialAnalysisAgent::class` (derived id), `'legal' => LegalAnalysisAgent::class` (aliased), `[NewsAnalysisAgent::class, 'Scan: {{ topic }}']` (derived id plus prompt), and `'bull2' => [BullCaseAgent::class, 'Argue against it.']` (aliased plus prompt). Pair prompts are ordinary step prompts: `{{ placeholder }}` templates interpolate, closures work, string prompts hash into the definition fingerprint, and a promptless branch still falls through to its conventional prompt method and the state's `prompt` key. Int-keyed pairs also make running the same agent twice in one fan-out natural (`SummarizeAgent`, `SummarizeAgent:2`), each with its own prompt. The pair is positional by design: a `[class => prompt]` map is rejected with guidance, because PHP silently collapses duplicate class keys in array literals before any code can see them.

## v0.15.0 — 2026-08-15

Prompt-ergonomics release: prompts move to the front of `step()`, string prompts gain `{{ placeholder }}` templates, and long prompts can be discovered by convention. No schema changes, no migration.

**Breaking (flagged per 0.x policy):**

- **`step()`'s second positional parameter is now `prompt`, not `as`.** The signature is `step($target, $prompt = null, $as = null, $label = null)`. Code using named arguments (every documented example to date) is unaffected and compiles to identical definitions with identical hashes. Code passing an alias positionally (`->step(Foo::class, 'alias')`) changes meaning **silently**: the string becomes the step's prompt and the id reverts to the class basename. Audit your workflows for positional second arguments before upgrading; this repository's own suite, docs, and stub contained none.

**Added:**

- **Positional prompts.** The prompt is the step's second argument: `->step(SummarizeAgent::class, 'Summarize the filings.')` or `->step(DraftAgent::class, fn ($state) => ...)`. The named `prompt:` form remains.
- **`{{ placeholder }}` templates in string prompts** (and debate `topic:` strings). Placeholders resolve against the workflow state at execution: dot paths (`{{ contract }}`, `{{ document.title }}`) and an `output:` form mirroring `$state->output()` (`{{ output:StepId }}` for a prior step's text, `{{ output:StepId.path }}` into its structured output). Booleans render as `true`/`false`; arrays JSON-encode. An unresolvable placeholder fails the step loudly with a `MissingWorkflowPromptException` naming it. There is no escape syntax by design: `{{` cannot occur inside valid JSON, so prompts containing JSON examples are unaffected, and the rare prompt needing a literal `{{` should use a closure. Only definition-authored strings interpolate; closure results and the state `prompt` fallback never do. Templates hash verbatim, so chained prompts become drift-visible, unlike the closures they replace. Theoretical behavior change: an existing string prompt that already contained a `{{ identifier }}` sequence now interpolates or fails loudly.
- **Conventional prompt methods.** An agent step defined without a prompt binds a workflow-class method named `{camelStepId}Prompt` when one exists, receiving the state and returning the prompt: `->step(RiskAnalysisAgent::class)` binds `riskAnalysisAgentPrompt()`; `as: 'risk'` binds `riskPrompt()`. The full resolution ladder is: explicit `prompt:` (always wins), conventional method, the state's `prompt` key, then `MissingWorkflowPromptException`. Applies uniformly to plain steps, `when()` branches, `evaluate()` bodies, and `parallel()` branches, which is the first way a parallel branch has ever been able to carry its own prompt. Ids that cannot be method names (`when:3`, a deduped `SummarizeAgent:2`) never match. Prompt methods may be `protected` and should be pure functions of the state they receive.
- Drift notes: a conventional method fingerprints as `(closure)`, so migrating a step from `prompt: $this->x(...)` wiring to the convention never changes its hash. Adding a method to a previously promptless step does change the hash (a real behavior change), and renaming a step's `as:` alias changes which method binds. The [agent steps](docs/agent-steps.md#prompts) and [defining workflows](docs/defining-workflows.md#step-ids) docs cover both.

## v0.14.0 — 2026-08-14

Feature release: a per-call audit on the step audit log, a cost-accounting fix for approval pauses, and a raised `laravel/ai` floor. Run `php artisan migrate` after upgrading (one additive migration on the steps table; existing behavior tolerates a not-yet-migrated schema until it runs).

**Added:**

- **Per-call audit on step rows.** Every agent-backed attempt now records a `calls` column: one entry per provider call inside the turn, in call order, carrying the SDK's invocation id, the provider and model that actually responded (under failover, not necessarily the ones requested), per-call token usage, the finish reason, and the turn's tool calls and results. Multi-prompt step bodies compose naturally: a `debate()` round row carries one entry per debater plus the judge, each group distinguishable by invocation id. The new `audit.step_calls` config option (`"full"` default, `"minimal"`) trims tool arguments and results, which can be large and can carry sensitive input. Non-agent steps record `null`. The data comes straight off the SDK's response object, so it works on `laravel/ai` v0.10.3 with no event listeners.

**Fixed:**

- **Attempts parked by a tool-approval pause now record their token usage** (and per-call audit). The pause follows a completed, billed provider call, but the interrupt path dropped the usage the executor was carrying, so every approval pause undercounted cost accounting; `awaitHuman`-heavy workflows undercounted the most. Discarded-result rows (a concurrent cancel or resume winning the race) now keep their usage too: the result is thrown away, the bill was still paid.

**Changed:**

- **Minimum `laravel/ai` raised from `~0.10.1` to `^0.10.3`.** The floor is deliberate, not just a widening: `laravel/ai` v0.10.2 added `AnthropicSchemaSanitizer`, which strips the JSON Schema keywords Anthropic's native structured output rejects (`minLength`, `pattern`, `minimum`, `multipleOf`, `uniqueItems`, most `format` values, and more) and folds each into the schema node's description so the model still honors the intent. Without it, a structured agent step (or a `debate()` judge, which must implement `HasStructuredOutput`) fails outright on Anthropic whenever its schema carries one of those keywords. Consumers resolving to 0.10.1 were exposed to that; the raised floor closes it. No package code changes: the suite and PHPStan pass unchanged against v0.10.3.

## v0.13.0 — 2026-08-10

Feature release extracted from a real chat-assistant integration: singleton keys, run groups, step labels and progress, app-owned run metadata, and a parallel-testing fix. Run `php artisan migrate` after upgrading (one additive migration on the runs table; existing behavior tolerates a not-yet-migrated schema until it runs).

**Breaking (flagged per 0.x policy):**

- `Workflow::start()` is now **final**. It is an entry point whose parameter list grows with the engine (this release adds `key:` and `group:`), and a subclass override with yesterday's signature is a fatal error at class-load time — better to refuse the override outright than break silently on each release. Wrap it in your own named method instead.

**Added:**

- **Singleton keys** — `start($input, key: "ticket:{$id}")` enforces one active run per business entity, scoped per workflow name. When an active run already holds the key, `start()` idempotently returns it (side-effect free: no `WorkflowStarted`, no step job; `wasRecentlyCreated === false` is the signal), adopting the requested `group:` only when the run has none. The guard is a unique `(name, active_key)` index — insert-first, no check-then-act race, savepoint-wrapped so it stays idempotent inside a caller's transaction on Postgres. Terminal transitions free the key; `retry()` re-claims it or throws when another run has claimed it since. Relies on NULLs not colliding in unique indexes: SQLite, MySQL, MariaDB, and Postgres are supported; SQL Server is not.
- **Run groups** — `start($input, group: "conversation:{$id}")` joins runs into a global group (mixed workflow types welcome). When the last active member reaches a terminal status, a `WorkflowGroupSettled($groupKey, $runs)` event delivers the group's terminal runs. Each run outcome is **stamped** settled exactly once (guarded per-row claims — concurrent settles partition runs, never double-deliver), so listeners need no locks or markers; event dispatch carries the same after-commit guarantee as the other lifecycle events, and the sweeper re-settles groups whose settle never ran. Groups settle again for later joiners; `retry()` and `cancel()` clear a stamped run's `settled_at` so its new outcome is delivered in the following settle.
- **Step labels and run progress** — every step-declaring method accepts `label: '...'`, and `$run->progress()` returns `['step', 'label', 'index', 'total', 'status']` resolved against the definition's top-level steps (cursors inside parallel or condition branches report the owning step; loops don't inflate the total; drifted definitions degrade to the raw id, never an exception). Unlabeled class steps humanize (`GatherTicketContext` → "Gather ticket context"); structural steps get purpose-built defaults instead of engine ids like `parallel:2`; `awaitHuman` falls back to its `reason`. Labels are excluded from the definition hash — adding them never trips strict drift for in-flight runs.
- **App-owned `meta` on runs** — a JSON column the engine never reads or writes (checkpoints, retries, sweeps, and resumes leave it untouched), with a concurrency-safe `$run->mergeMeta([...])` helper. For external references, audit tags, notification receipts.

**Fixed:**

- **`parallel()` on the sync queue no longer requires concurrency configuration.** Test suites (sync queue driver) previously routed parallel branches through Laravel Concurrency's default process driver, whose child processes share neither the test database nor `Agent::fake()` state — failing with the opaque "Concurrent process failed with exit code [255]" unless consumers discovered `config(['concurrency.default' => 'sync'])`. Branches now run in-process on the sync queue (`parallel.sync_driver` config opts back into isolation); real queue connections are unaffected.

## v0.12.0 — 2026-07-31

Feature release: class-based workflows start themselves. No schema changes, no migration.

**Added:**

- Static `Workflow::start()` — class-based workflows can now start themselves: `ContractReview::start(['contract' => $text], participant: $user)` is equivalent to `AgentWorkflow::start(ContractReview::class, ...)`. Resolves the manager through the container, so `AgentWorkflow::fake()` records these runs too. The facade remains the way to start string-named workflows.

## v0.11.0 — 2026-07-30

Feature release: durable multi-agent debate. No schema changes, no migration.

**Added:**

- `debate()` — two or more debater agents argue a topic in rounds (openings, then rebuttals) while a structured-output judge rules on the transcript after each round; the loop stops on `judge.consensus === true` (or a custom `until:` predicate) or at the `rounds` cap, which is an outcome (`satisfied: false`), not a failure. Compiles to `evaluate()` + a package-shipped callback body, so each round is one checkpoint and one audit row, drift hashing and the dashboard treat it as machinery they already know, and the graph stays static. `as:` is required (debates are too long-lived for positional ids). Costs grow **quadratically** with `rounds`; `transcriptWindow:` bounds the debaters' prompts to the last N rounds (the judge always sees the full transcript). Full guide, including the raw-primitives recipe it packages: [docs/agent-debate.md](docs/agent-debate.md).
- `Support\Transcript` — a JSON-safe view over `steps.{id}.transcript` (`append()`, `entries()`, `bySpeaker()`, `round()`, `render(lastRounds:)`), for round bodies and downstream synthesis prompts.
- `AgentStepResult::sum()` — key-wise usage aggregation for multi-call step bodies.
- Callback steps now receive their `StepDefinition` as a second argument (read your own id/config instead of hard-coding it) and may return a `StepResult` to report token usage on the audit row.

**Guard rails, priced in tokens:**

- A judge whose verdict lacks a `consensus` boolean fails the debate loudly **after one round** under the default predicate (instead of silently burning every round and reporting "no consensus"); with a custom `until:` the check is waived. The judge's `HasStructuredOutput` interface is still checked at definition time, as are debater count/type, duplicate speaker names, `rounds >= 1`, and `transcriptWindow`.
- A debater (or judge) pausing on SDK tool approvals mid-round fails the round loudly naming the participant — per-speaker decision replay doesn't exist yet, and checkpointing a half-spoken round would be worse. Move approval-gated tools outside the debate.
- The shipped protocol prompts are versioned into the definition hash (`DebateRoundStep::PROTOCOL_VERSION`), so a package upgrade that changes them refuses to resume an in-flight debate under strict drift mode rather than silently altering the next round.

**Compatibility note on the callback signature:** every single-parameter callback (`__invoke(WorkflowState $state)`) keeps working unchanged — PHP ignores the extra argument. A callback that declares an *optional second parameter* of another type (`__invoke(WorkflowState $state, ?Foo $extra = null)`) now receives the `StepDefinition` there and will TypeError; rename or retype that parameter.

## v0.10.0 — 2026-07-30

Hardening release from a full-package review: parallel × agents, parallel × crash recovery, interrupt payload integrity, and operational scale. Run `php artisan migrate` after upgrading.

**Breaking (flagged per 0.x policy):**

- Unaliased `evaluate()` steps are now id'd by the bare class basename (was `evaluate:{Basename}`), so `output(Target::class)` addresses the loop's checkpoints like any other step's. This changes those workflows' definition hashes — in strict drift mode, in-flight runs refuse to resume after deploying the upgrade (pass `as: 'evaluate:{Basename}'` to keep the old id).
- `awaitHuman()` schemas containing closures or non-Stringable rule objects (`Rule::enum`, `Password::min`) now throw at definition time — they silently degraded to empty constraints through JSON persistence. Stringable rules (`Rule::in(...)`) are cast to their string form, which also corrects those definitions' hashes.
- Duplicate explicit `as:` aliases throw instead of being silently renamed with a numeric suffix.
- Registering a different definition under an existing workflow name throws instead of silently overwriting; `WorkflowRegistry::forget()` is the explicit escape hatch.
- `resume()`/`deliverEvent()` payloads may no longer contain the engine-reserved `steps` key.
- Step targets must be real classes at definition time (typos no longer become callback steps that explode in a worker).
- A callback step returning non-null that isn't a `WorkflowState` now fails the step instead of silently discarding the value.
- An agent pausing on tool approvals inside a `parallel()` branch now fails the run with an explicit error (it was silently recorded as a completed branch); inside `evaluate()` bodies the pause now correctly parks the run and `resume()` continues the loop.
- Lifecycle events implement `ShouldDispatchAfterCommit`, matching the job dispatches — listeners no longer observe runs that a caller's transaction rolls back.

**Fixed:**

- The default parallel merge deep-merges the engine-owned `steps` subtree per step id — two or more agent branches without a `merge:` closure always conflicted on the `steps` key and could never complete.
- Crashed parallel fan-outs are recoverable: `retry()` supersedes all in-flight audit rows (stale branch rows wedged every retry as a "duplicate delivery"), the duplicate-delivery guard throws instead of returning the input snapshot as a branch result (sync mode silently completed with a crashed branch's work missing), and the batch completer only merges branch rows from the current fan-out generation.
- `cancel()` (or a failure) now stops queued branches from executing full agent turns against a dead run.
- The strict definition-drift policy is enforced in parallel branch jobs and the batch completer, not just cursor steps — and in `resume()`/`deliverEvent()` *before* the response is consumed, so a strict-mode refusal leaves the gate open.
- Queue redeliveries of steps that are still executing (retry_after shorter than the step) no longer fail the run and discard the original attempt's result.
- Events serialize models by identifier for queued listeners; `WorkflowFailed` drops its Throwable at the serialization boundary (read `failure_reason` in queued listeners).

**Added:**

- `awaitEvent($event, schema:)` — validates delivered payloads and strips undeclared fields, mirroring `awaitHuman()`.
- `assertStepRanTimes()` on the fake, and all workflow-name assertions now accept class names like `start()` does (`assertNotStarted(Flow::class)` previously *passed* even when the flow had started).
- `audit.step_output` config: `full` (default) or `minimal` — full snapshots on every audit row grow O(n²) over a run's life.
- Indexes for the hot paths: `(run_id, resolved_at)` on interrupts (PostgreSQL/SQLite had no run_id index at all), `(status, updated_at)` on runs for the sweeper.
- The sweeper chunks its scans, checks in-flight attempts with one query per chunk, and re-dispatches a stranded run once per staleness window instead of once per tick (no more feeding duplicate jobs into the backlog it's waiting out).

**No more foreign key constraints.** The `run_id` FK constraints (and their `ON DELETE CASCADE`) are gone from the steps and interrupts tables: referential integrity is enforced by the engine — child rows are only ever created through a run — so the constraints bought write overhead and lock coupling for nothing. Deleting a `WorkflowRun` **model** still removes its steps and interrupts (the cascade moved to the model layer); mass deletes via the query builder bypass model events, so delete runs through their models. An additive migration drops the constraints on existing installs.

**Migration strategy.** Every schema change now ships as an additive migration, so upgrading is always `composer update && php artisan migrate` (changes to the base create-tables migration ship with a paired additive migration that converges existing installs). This release includes a catch-up migration that adds the interrupts table's `timeout_at` column on installs migrated at v0.8 or earlier (the v0.9 "re-run the migration" instruction was not actionable — Laravel records the migration as already run).

## v0.9.0 — 2026-07-28

**`awaitHuman()` timeouts.** Additive. *(Correction: this entry originally said existing installs must "re-run the migration" to pick up the interrupts table's `timeout_at` column, which `php artisan migrate` will never do — v0.10 ships a catch-up migration instead; just run `php artisan migrate` after upgrading.)*

- `awaitHuman(..., timeout:, timeoutResponse:)` — `timeout:` (seconds or any `DateInterval`) is the wait's SLA, enforced by the scheduled `agent-workflows:sweep` command. With a `timeoutResponse:`, the run resumes with that payload (validated against the schema like any human answer); without one, the run fails at the gate. `$run->retry()` re-arms the same wait with a fresh deadline.
- The interrupt row records `timeout_at`, so approval UIs can show the deadline.
- The timeout and timeout response count toward the definition hash; the reason remains cosmetic.

## v0.8.0 — 2026-07-28

**Typed workflow state.** Additive, no breaking changes. See [docs/typed-state.md](docs/workflow-state.md).

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
