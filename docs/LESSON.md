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

