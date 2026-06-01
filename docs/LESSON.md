# LESSON — laravel-ai-finops

Non-obvious discoveries, API contract details, test workarounds, and lessons learned (including from
Copilot review comments). **Load this before working and pass it to every subagent.** Dated `YYYY-MM-DD`.

## 2026-05-27 — Ecosystem & architecture facts

- **Backbone = `laravel/ai`.** Verified via composer.json that Padosoft AI packages depend on the
  official `laravel/ai` SDK (`AskMyDocs`, `laravel-ai-regolo`, `laravel-ai-price-intelligence`;
  `eval-harness` opt-in). `laravel-ai-regolo` is itself a `laravel/ai` PROVIDER. ⇒ One metering hook on
  the `laravel/ai` lifecycle sees every provider's calls.
- **`laravel-ai-chat` is a DEMO only** (laravel/ai + Vercel AI SDK showcase). Do not treat it as a
  capability to integrate.
- **`agentic-qa-kit` is NOT a Laravel package.** It's a Bun/TypeScript monorepo (`@aqa/*` workspaces,
  Biome, Playwright, packs). No composer.json. Integrate as an EXTERNAL QA runner that drives the app
  over HTTP; correlate cost to scenarios via a `trace-id` request header.
- **`laravel-flow` and `laravel-ai-search-providers` do not call LLMs directly** (orchestrator / web
  search). Not metering gaps; flow is where we propagate `trace-id` for per-step cost attribution.
- **Two third-party inspirations to beat:** `subhashladumor1/laravel-ai-guard` (enforcement + static
  pricing tables that go stale) and `jonaaix/laravel-ai-costs` (tracking only, pricing via LiteLLM DB,
  no budget/alert/block). We unify tracking + enforcement + governance with dynamic pricing.
- **Reference governance repo** `../product_image_discovery_admin`: React 18 + Vite (plain CSS, no
  Tailwind, no TS), Herd PHP via `npm run phpunit` → `scripts/run-php.mjs`, Playwright projects
  desktop(1440×900)+tablet(1024×768), phpunit Feature/Unit suites. We modernize the admin to
  React+Vite+**Tailwind** (latest) per Lorenzo's request.
- **Decisions (2026-05-27, Lorenzo):**
  1. **Pricing source = BOTH.** Mirror the LiteLLM pricing DB AS the base; a Padosoft local DB entry,
     if present, OVERRIDES and wins. (`PricingRegistry`: LiteLLM base ⊕ Padosoft overrides.)
  2. **`laravel/ai` compat:** keep `^0.6.8 || ^0.7` if feasible (abstract the hook behind an adapter);
     otherwise target the latest available version.
  3. **Versions:** React / Vite / Tailwind = latest stable (currently React 19, Vite 8, Tailwind 4 —
     pin actual latest at scaffold time). Laravel 13.x. PHP `^8.3` to also cover 8.4 and **8.5**.
- **Copilot reviewer gotcha:** `gh pr edit --add-reviewer @copilot` can fail on `read:project`; fall back
  to GraphQL `requestReviewsByLogin` with bot `copilot-pull-request-reviewer[bot]`. REST
  `reviewers[]=copilot` is not equivalent (200 without a real review request). On PR #1 `gh pr edit`
  DID work (exit 0) and `requested_reviewers` showed the Copilot bot — verify with
  `gh api repos/<owner>/<repo>/pulls/<n>/requested_reviewers`.
- **Copilot posts inline comments, not a formal review object** (PR #1): poll
  `gh api .../pulls/<n>/comments` (count), not just `gh pr view --json reviews` (which stayed empty).
- **Package routes must default to `['api']`, NOT `['web']`.** The `web` middleware group is not
  registered in the Orchestra Testbench package context → requests error at Kernel middleware resolution.
  Pattern: default `['api']`, add `auth_middleware` (['auth']) on privileged endpoints, and let the
  admin/host set `['web']` where session+CSRF exist. (Copilot P1 on PR #1.)
- **Keep the plan in-repo (`docs/PLAN.md`).** `%USERPROFILE%`/`~/.claude` paths are not portable for
  other contributors/CI (Copilot flagged 4× on PR #1). Reference `docs/PLAN.md` + note the local copy.
- **CI: PHP 8.5 passed too** on `shivammathur/setup-php@v2` — the experimental (non-blocking) 8.5 job
  was green for the M0 scaffold.
- **`AgentStreamed extends AgentPrompted` in laravel/ai** — safe to register `AgentStreamed` events
  against `handleAgentPrompted(AgentPrompted $event)` because of inheritance. No separate handler needed.
  Worth a regression test if the inheritance ever changes.
- **`$collection->first()->property ?? fallback` is safe in PHP** — the `??` operator uses `isset`-style
  semantics and handles `null` object property access without throwing. No `?->` required (though `?->`
  is clearer and preferred for explicit intent).
- **Do not use `currency.display` label on aggregated totals when no FX conversion is in place** —
  KPI endpoints that SUM stored amounts must report in the stored base currency (`currency.base`).
  Using the `display` currency label on unconverted amounts will silently return mislabeled figures.
  FX conversion belongs to a future M (store amounts in `base`; convert on read when `fx_provider` is set).
- **`sync()` MUST guard `synced_at` cache update** — only update the "last synced" cache key and return
  `synced: true` when the pricing source actually returned > 0 models. Returning `synced: true` on a
  failed/empty sync gives a false operational state and causes operators to believe pricing is current
  when it may be stale/empty.
- **Validate `nullable|date` before calling `$request->date()`** — `$request->date('param')` throws
  `\Carbon\Exceptions\InvalidFormatException` (uncaught 500) on invalid input. Always validate date
  parameters with `$request->validate(['param' => 'nullable|date'])` before calling `date()`.
- **`updateOrCreate` + `wasRecentlyCreated` for correct 201/200** — when a route uses
  `Model::updateOrCreate`, the response code should reflect whether a record was created (201) or
  updated (200). Use `$model->wasRecentlyCreated ? 201 : 200`.

## 2026-05-31 — M8 multi-source pricing

- **Run tests with `php vendor/bin/phpunit` from PowerShell**, NOT the bare `vendor/bin/phpunit`
  shim under the Bash tool — its `#!/usr/bin/env php` shebang fails with `'php': No such file or
  directory` because `php` is not on the git-bash PATH on this Windows host (it IS on the PowerShell
  PATH). PHP 8.4.21, PHPUnit 12.5.27. Baseline before M8 = 117 tests green.
- **M8 design = `docs/superpowers/specs/2026-05-31-multi-source-pricing-design.md`** + plan
  `docs/superpowers/plans/2026-05-31-multi-source-pricing.md`. Key facts from live research:
  neither LiteLLM nor OpenRouter timestamps individual prices (freshness = OUR per-source sync time);
  OpenRouter has NO inference markup (pass-through; ~5.5% credit-funding fee → model as overlay only);
  real cost = who actually billed you (provider_source_map); regolo.ai has NO price API → manual source
  (EUR, per-1M). OpenRouter price varies 3–10× by upstream endpoint (endpoint ingestion deferred Ph2).
- **`PricingRegistry` now depends on `PricingSourceManager`, not a single `PricingSource`.** Anything
  constructing `new PricingRegistry(...)` must pass a manager: wrap a fake with
  `new PricingSourceManager(['litellm' => $source], $config)`. The bare `PricingSource::class` binding
  stays (LiteLLM) for back-compat / the controller catalog, but resolution flows through the manager.
- **Hermetic-test pattern for multi-source:** `TestCase::setUp` binds `PricingSourceManager` to wrap
  whatever `PricingSource::class` resolves to (`['litellm' => $app->make(PricingSource::class)]`). So a
  test that rebinds `PricingSource` with its own models still flows through the registry — the 4 API
  tests (Pricing/Routing/Settings/WhatIf) needed NO change. Without this the manager would build the
  REAL LiteLLM/OpenRouter sources and hit the network. Also `forgetInstance(PricingSourceManager)`.
- **Any test that writes to the DB needs `use Illuminate\Foundation\Testing\RefreshDatabase`.** The
  package registers migrations via `loadMigrationsFrom`, but Testbench only runs them when the trait
  (or an explicit migrate) is present. Symptom without it: `no such table: ai_finops_*`.
- **Manual `manual` source vs override lookup:** `ModelPrice::fromLiteLLM` hard-codes USD, so the
  `manual` source is resolved in `PricingRegistry` via the currency-aware `override()` lookup (preserves
  EUR), NOT via `fromLiteLLM`. `ManualPricingSource` exists for the merged catalog / per-source status.
- **Freshness tie-break:** `PricingRegistry::isFresher` prefers the later `syncedAt()`; equal/both-null
  falls back to `pricing.default_winner` order (lower index wins). `manual` is skipped in the
  freshest-wins loop (it's the override, handled by precedence/map).
- **Subscription €0 coverage is applied in `MeteringListener`** after pricing: `SubscriptionWindow::
  activeFor(provider, tenant, model, now())` → zero `CostBreakdown`, `CallStatus::Covered`,
  `metadata.covered_by`; the would-be rates stay in `metadata.rate_*` for "value consumed" analysis.
  Wrapped in try/catch so a missing table never breaks metering. `RoutingEngine` reuses `activeFor` to
  zero the cost metric (prefer covered providers).
- **Overhead overlay is estimate-only:** `CostCalculator::withOverhead($cost, $provider)` reads
  `pricing.fees.<provider>.markup_pct` via the `config()` helper (no constructor change, so
  `new CostCalculator` keeps working) and is wired into the what-if projection — never the raw ledger.
- **PowerShell `--filter "A|B"` breaks** (the `|` pipes); run the full suite or one `--filter Name`.

## 2026-06-01 — M9 cost resolution cascade

- **laravel/ai drops provider cost + raw payload by design.** `Usage` carries tokens only
  (`vendor/laravel/ai/src/Responses/Data/Usage.php`); the OpenRouter gateway's `extractUsage()`
  reads tokens and discards `usage.cost`. We recover it WITHOUT forking: the gateway builds its client
  via Laravel's **`Http` facade**, so `Http::globalResponseMiddleware()` sees the raw JSON first. The
  middleware filters by **body shape** (`usage.cost` present) because Laravel's response middleware
  does NOT expose the request URL/host. Only the usage/cost block + id are captured (never content).
- **`RawResponseCapture` is `scoped`** (like `TraceContext`); the global middleware is registered once
  in boot but resolves the capture lazily (`app(RawResponseCapture::class)` inside the closure) so it
  stays request-scoped under Octane.
- **Cascade order** (`CostResolutionService`): (a) `ActualCostResolver` → (b) actual tokens × tariff →
  (c) `TokenEstimator` × tariff. Subscription coverage is applied AFTER in `MeteringListener`
  (method=`covered`, cost 0, but `billed_cost` keeps the would-be amount).
- **Case (c) estimates BOTH** input (from the prompt, threaded via `$event->prompt` → stringify) and
  output (from `$response->text`). Heuristic `max(chars/4, words×1.3)`; `yethee/tiktoken` auto-binds
  when installed (`class_exists(EncoderProvider::class)` — the `::class` constant doesn't require the
  class to exist, so the `use` import + `class_exists` are safe with the package absent).
- **`call()` is a reserved Testbench method** (HTTP helper) — name test helpers `envelopeFor()` etc.
  (joins the `seed()`/`status()` reserved-name list).
- **MeteringListener constructor changed** (CostCalculator → CostResolutionService); any manual
  construction in tests must build a `CostResolutionService(NullActualCostResolver, registry,
  CostCalculator, HeuristicTokenEstimator, config)`.
- Provider matrix proven in `CostResolutionCascadeTest`: OpenRouter→actual, OpenAI/Anthropic/Gemini→
  computed, regolo→computed (manual EUR/1M), unknown→estimated; fal→unit-cost in `FalUnitCostTest`.

## 2026-05-27 — M2 review findings

- **Never accept unimplemented `scope_type` values in validation** — `SettingsController` accepted
  `'feature'` in `Rule::in(...)` but `PolicyEngine::killSwitchReason()` fell through to
  `default => false` for that type. An operator storing an active `feature` kill switch would believe
  they're protected while enforcement silently does nothing. Rule: only expose scope types that are
  actually evaluated by the engine.
- **Kill-switch scope_id must be validated conditionally** — for `global` scope, `scope_id` must be
  absent/null (normalise to `null`); for `provider`/`tenant` scopes, `scope_id` is required. Without
  this, callers can create inert switches that never match.
- **Narrow broad `Throwable` catches in enforcement paths to `QueryException`** — catching all
  `Throwable` in `BudgetResolver::applicableTo()` and `PolicyEngine::killSwitchReason()` silently
  swallows PHP `TypeError`, `Error`, etc. and causes fail-open enforcement. Only real DB errors
  (transient connectivity) should be swallowed; logic errors should propagate.
- **`BudgetExceededException` must use a generic HTTP message** — passing the detailed block reason
  as the `HttpException` message leaks budget names and tenant IDs into HTTP 402 responses. Use a
  generic "AI spend limit reached" message for the HTTP layer; keep the detailed reason on
  `$e->blockReason` for internal logging.
- **M2 hard-budget enforcement is reactive, not prospective** — `PolicyEngine` blocks a call only
  when `spent >= limit` is ALREADY true. A call that would push spend over the limit is still allowed
  through; the NEXT call triggers the block. This is the standard approach for pre-flight enforcement
  without token prediction. Prospective enforcement (block when `spent + estimated_cost >= limit`)
  requires token counting from the `PromptingAgent` event and is deferred to M3.
- **N+1 in `BudgetController::index()` + `tree()`** — each budget triggers a `spend()` DB query.
  Acceptable for M2 with small budget counts; batch-aggregate in M3 when budgets can be numerous.
- **`unique(['scope_type', 'scope_id'])` with nullable `scope_id` is racy on MySQL/Postgres** —
  NULL != NULL in unique constraints allows concurrent inserts of two `(global, NULL)` rows. The
  `updateOrCreate` call mitigates this in normal use but is not atomic. Use a sentinel value (e.g.
  `''`) for global scope if this becomes an issue, or add a DB-level partial index.

