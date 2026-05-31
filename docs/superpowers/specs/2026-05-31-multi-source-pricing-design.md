# Design Spec — M8: Multi-Source Pricing (LiteLLM ⊕ OpenRouter ⊕ Manual) + Subscription Coverage

- **Date:** 2026-05-31
- **Status:** Design — pending user review
- **Package:** `padosoft/laravel-ai-finops` (admin companion: `../laravel-ai-finops-admin`)
- **Milestone:** M8 (core is feature-complete through M7; this is an additive milestone)

---

## 1. Motivation

Today pricing resolves through a single `PricingSource` (LiteLLM mirror) as base, with local DB
overrides that win (`PricingRegistry`). The README promises "always-fresh pricing". A second
community pattern hooks **OpenRouter's live models API** for fresh prices. We want to support
**both feeds plus a first-class manual source** (e.g. **regolo.ai**, which has *no* price API),
choose per provider which feed is authoritative, break ties by freshness, and — critically —
keep the **historical ledger as the immutable truth of real cost incurred**, while config drives
**forward-looking** estimates.

Two new cost realities also need modeling, both surfaced by the user:

1. **Flat-rate subscription coverage** (e.g. *Claude Max*, *OpenAI Pro*): for a provider, within a
   manually-set date window, calls are tracked but cost **€0** (you already paid the subscription).
   When the provider signals exhaustion, the operator shortens the window and calls revert to paid.
2. **Account-level overhead** (e.g. OpenRouter's ~5.5% credit top-up fee): a per-provider markup %
   that matters for *estimates*, not for the raw per-call ledger.

---

## 2. Research findings (verified live 2026-05-31)

### 2.1 LiteLLM vs OpenRouter

| Dimension | **LiteLLM** | **OpenRouter** |
|---|---|---|
| Nature | Static JSON in Git (also fetchable at runtime) | Live REST API on a running gateway |
| Update | Human PRs/commits, **~50 commits/month**, often **day-0** | Real-time (live gateway config) |
| Models | **2,748 entries** | **343** in public list (+ up to ~14 per-provider *endpoints* per model) |
| Providers | **110** `litellm_provider` values (OpenAI, Azure, Bedrock, Vertex, OCI, Databricks, watsonx, **fal_ai**, Replicate, Stability… *includes `openrouter` itself, 95 entries*) | **56** model authors; real serving providers (DeepInfra, Together, Groq, Cloudflare…) live at the **endpoint** level |
| Price unit | USD/token, JSON float (`2.5e-06`) | USD/token, JSON string (`"0.0000002"`) |
| Per-price effective date | ❌ only `deprecation_date`; freshness = git commit time | ❌ only `created` (listing date, not price-change) |
| Schema richness | ~140 fields: cache/batch/flex/priority/long-context tiers, per-second/image/pixel/audio | Flatter: `prompt/completion/request/image/input_cache_read/input_cache_write/internal_reasoning` |
| Markup | N/A (catalog of provider list prices) | **No inference markup** (pass-through). Revenue = **~5.5% credit top-up fee**; BYOK ~5% beyond ~1M req/mo |
| Multi-endpoint | — | **Yes** — `GET /api/v1/models/:author/:slug/endpoints`; same model varies **3–10×** by upstream |

Sources: `https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json`,
`https://api.github.com/repos/BerriAI/litellm/commits?path=model_prices_and_context_window.json`,
`https://openrouter.ai/api/v1/models`, `https://openrouter.ai/docs/faq` ("we pass through the pricing
of the underlying model providers without any markup"), `https://openrouter.ai/api/v1/models/:author/:slug/endpoints`.
(OpenRouter exact fee % is widely reported but JS-rendered on the FAQ; treat the 5.5% as configurable, not hard-coded.)

### 2.2 regolo.ai

OpenAI-compatible (`https://api.regolo.ai/v1`); `/v1/models` returns **IDs only, no prices**. Prices
published only on the HTML page in **EUR, per-1M-token**. **Not** in LiteLLM, **not** in OpenRouter.
A `padosoft/laravel-ai-regolo` provider already exists (referenced in `MeteringListener`). → regolo
must be served by the **manual source** with EUR / per-1M entry support.
Sources: `https://api.regolo.ai/v1/models`, `https://regolo.ai/pricing/`.

### 2.3 The three findings that shape the design

1. **Neither feed timestamps individual prices.** "Freshest wins by price date" is therefore not
   derivable from feed data — only from **our own per-source sync time**. We will stamp `synced_at`
   per source on every sync and use it as the freshness signal; env config breaks ties / unknowns.
2. **OpenRouter has no inference markup**, but the *real* price depends on **which upstream endpoint**
   served the call (3–10× spread). Model-level price now; per-endpoint price is a Phase-2 extension.
   The ~5.5% top-up fee is account-level → model as an optional per-provider overhead %.
3. **Real cost = whoever billed you.** Direct provider/fal/Replicate → LiteLLM (+ its tier fields).
   Routed via OpenRouter → OpenRouter endpoint price. This is *why* keeping multiple sources is correct.

### 2.4 Current state already supports "past = frozen truth"

`MeteringListener` already records, per call, the **real** `provider`/`model` returned by the SDK,
computes cost via `PricingRegistry` + `CostCalculator` at that instant, and freezes
`cost_total`/`currency` + `metadata.price_source` on an immutable ledger row. History is **never**
re-priced from today's prices. Gaps to close: capture the **actual upstream endpoint** (OpenRouter),
freeze the **exact per-token rates** used (not just the source name), and stamp `source_synced_at`.

---

## 3. Goals / Non-goals

**Goals**
- Add OpenRouter as a `PricingSource`, enabled by config + presence of an (optional) API key in env.
- First-class **manual source** for providers with no feed (regolo.ai), with EUR / per-1M entry.
- Multi-source resolution: **manual override → per-provider authority map → freshest `synced_at` →
  env tiebreak** (the user's "option 3 / both levels").
- **Subscription coverage windows**: per-provider date window ⇒ covered calls cost **€0**, tagged,
  operator-shortenable; routing can prefer covered providers.
- **Per-provider overhead %** overlay for *estimates* (OpenRouter funding fee et al.); ledger stays raw.
- Freeze richer provenance on the ledger (upstream endpoint, exact rates, source sync time).
- Per-source sync + status; admin API contract; everything config-toggled; secrets as `has_*` only.

**Non-goals (this milestone)**
- OpenRouter per-endpoint price ingestion (schema is prepared; ingestion is Phase 2).
- Prepaid/deposit (acconti/prepagate) cash-flow accounting — explicitly out of scope.
- Subscription amortization / break-even spreading — replaced by the simpler €0-coverage-window model.
- Building the admin UI (handled by the admin repo; this spec emits its contract — §10).

---

## 4. Architecture

### § A — From single source to a source *manager*

- `PricingSource` interface gains **`syncedAt(): ?\DateTimeInterface`** (reads a per-source
  `…:synced_at` cache key written on each successful `sync()`). Existing test fixture
  `tests/Support/ArrayPricingSource.php` implements it (returns a fixed/now value).
- `LiteLLMPricingSource` (`name()='litellm'`) — **unchanged behavior**, plus it writes `synced_at`.
- **New** `OpenRouterPricingSource` (`name()='openrouter'`):
  - Fetches `config('ai-finops.pricing.openrouter.url', 'https://openrouter.ai/api/v1/models')`.
  - No auth required for listing; sends `Authorization: Bearer <key>` **iff** a key is configured
    (raises rate limits; required later for `/endpoints`). Key from
    `env('AI_FINOPS_PRICING_OPENROUTER_KEY')` — **never serialized**.
  - Normalizes the response into the LiteLLM-style attribute map so `ModelPrice::fromLiteLLM()` keeps
    working: `pricing.prompt`→`input_cost_per_token` (string→float), `pricing.completion`→
    `output_cost_per_token`, `pricing.input_cache_read`→`cache_read_input_token_cost`,
    `pricing.input_cache_write`→`cache_creation_input_token_cost`; provider from `top_provider`/author.
  - Enabled only when `config('ai-finops.pricing.openrouter.enabled')` **and** a key is present *or*
    `allow_keyless` is true. Graceful network degradation like LiteLLM (cache fallback).
- **New** `PricingSourceManager`:
  - `sources(): array<PricingSource>` — enabled sources in configured order.
  - `merged(): array<string,array>` — union catalog for listing, each attr tagged with `_source`.
  - `syncAll(): array<string,int>` — sync each enabled source, return per-source counts.
- Container: keep `PricingSource::class` bound to LiteLLM (back-compat default), **add**
  `PricingSourceManager` singleton; `PricingRegistry` depends on the **manager** (not a single source).

### § B — Resolution algorithm (PricingRegistry)

For `priceFor(model, provider)`:
1. **Manual DB override** — wins if `pricing.overrides_win` (unchanged top priority; regolo, negotiated).
2. **Per-provider authority map** — `config('ai-finops.pricing.provider_source_map')`, e.g.
   `['openrouter'=>'openrouter','openai'=>'litellm','anthropic'=>'litellm','fal_ai'=>'litellm','regolo'=>'manual']`.
   If `provider` is mapped, take that source's price (this is the "who actually bills you" rule).
3. **Else, among enabled sources that have the model** — pick the one with the **freshest
   `syncedAt()`**; on tie/unknown use `config('ai-finops.pricing.default_winner')` ordering.
4. Memoized per request (existing pattern). `ModelPrice.source` carries provenance.

### § C — Subscription coverage windows + overhead overlay

**Subscription coverage (new model `…subscription_windows`):** per-provider, model-agnostic by
default. Columns: `provider`, `label`, `starts_at`, `ends_at` (nullable = open), `enabled`,
`tenant_id` (nullable), `model` (nullable = all), `note`, timestamps. Audit-observed.

- In `MeteringListener` (after price/cost computed): if an **active** window matches
  `(provider, now [, tenant, model])`, set `cost_total = 0` (tokens preserved), tag
  `metadata.covered_by = <label>`, set `status = 'covered'` (new `CallStatus::Covered`).
- The list price is still resolved and frozen in `metadata.rate_*` (Q2 said tokens are €0 in the
  *cost* columns; we keep the would-be rate in metadata so per-model "value consumed" stays analyzable).
- Operator shortens `ends_at` when the provider signals exhaustion → later calls priced normally.
- **Routing synergy:** `RoutingEngine` can treat covered providers as effectively-free to "stay
  within the flat-rate" (cost-aware routing flag already exists).

**Overhead overlay (estimates only):** `config('ai-finops.pricing.fees')` keyed by provider/source
with `markup_pct` (default 0). Applied by `CostCalculator` **only** on forward paths (preflight
estimate, what-if, forecast) — **never** mutates the raw metered ledger row.

### § G — Manual-entry surfaces (API + admin masks) — first-class

Because some providers have **no feed** (regolo.ai) and subscriptions are operator-knowledge, both
must be **hand-editable** via API + admin forms (not config-only):

- **Manual prices** (feed-less providers): full CRUD on `pricing_overrides` (the existing
  `pricing/overrides` endpoints, extended with `unit` per-token/per-1M + `currency` EUR/USD, and
  optional `effective_from`/`note`). This *is* the `manual` source. Admin "Add price" mask:
  provider, model, input/output (+ optional cache), unit toggle (per-token | per-1M), currency,
  effective-from, note. Bulk paste/import for a provider's price list is a nice-to-have.
- **Subscriptions (canoni)**: full CRUD on a new `subscription-windows` endpoint + admin mask:
  provider, label, from/to (to nullable = open), tenant/model scope, enable, note. This is the
  €0-coverage model from §C — a "canone" form, *not* an amortization calculator.

Both surfaces are audit-observed and tenant-aware; secrets never appear (manual prices carry no keys).

### § D — Ledger: freeze richer provenance (past truth)

Additive, backward-compatible — written into the existing `metadata` JSON (no required new columns):
`upstream_provider` (real OpenRouter endpoint when known), `rate_input`/`rate_output` (exact per-token
rates applied), `source_synced_at`. History is never re-priced. Config-priority fallback (§B step 3)
applies only when `provider == 'unknown'` at query time.

### § E — Sync & status

- `PricingRegistry::sync()` → `PricingSourceManager::syncAll()`; each source stamps its `synced_at`.
- `CapturePricesCommand` / cron sync all enabled sources.
- `PricingController`: `models()` → manager `merged()` with a `source` field per row + `?source=` filter;
  `syncStatus()` → **per-source** `{name, synced_at, models, enabled, has_key}` array.

---

## 5. Config (new `pricing` block, additive)

```php
'pricing' => [
    'overrides_win' => true,

    // Ordered, per-source toggles. First listed = highest default precedence.
    'sources' => ['manual', 'litellm', 'openrouter'],
    'default_winner' => ['manual', 'litellm', 'openrouter'], // tie / no synced_at

    'litellm' => [
        'enabled' => env('AI_FINOPS_PRICING_LITELLM', true),
        'url' => env('AI_FINOPS_PRICING_LITELLM_URL', '…model_prices_and_context_window.json'),
    ],
    'openrouter' => [
        'enabled' => env('AI_FINOPS_PRICING_OPENROUTER', false),
        'url' => env('AI_FINOPS_PRICING_OPENROUTER_URL', 'https://openrouter.ai/api/v1/models'),
        'key' => env('AI_FINOPS_PRICING_OPENROUTER_KEY'),     // optional; never serialized
        'allow_keyless' => true,                               // public list works without a key
        'use_endpoints' => false,                              // Phase 2: per-provider endpoint prices
    ],

    'sync_cron' => env('AI_FINOPS_PRICING_SYNC_CRON', '0 4 * * *'),

    // Who is authoritative per provider (the "who bills you" rule).
    'provider_source_map' => [
        'openrouter' => 'openrouter',
        'regolo'     => 'manual',
        // openai/anthropic/azure/bedrock/vertex/fal_ai/… default to 'litellm'
    ],

    // Per-provider account-level overhead for ESTIMATES only (not the raw ledger).
    'fees' => [
        // 'openrouter' => ['markup_pct' => 5.5],
    ],

    'discounts' => ['prompt_cache' => true, 'batch_api' => true, 'committed_use' => true],
],
```

---

## 6. Data model / migrations

1. **New** `…subscription_windows` (§C). Composite index `(provider, enabled)`; nullable
   `tenant_id`/`model` use `''` sentinels where they participate in uniqueness (per RULES gotcha).
2. **`pricing_overrides`** — additive columns to support manual feed-less providers like regolo:
   `unit` enum (`per_token`|`per_million`) default `per_token`; keep existing `currency`; add nullable
   `effective_from` (date) + `note`. Entry helper converts per-1M→per-token on read; resolution honors
   currency via existing `FxConverter`. This table backs the **hand-entry cost mask** (§G).
3. **No** change to `usage_ledger` columns — new provenance goes into existing `metadata` JSON.

`PricingOverride` model gets the `unit` cast and `toModelPrice()` normalizes per-1M→per-token.
New `SubscriptionWindow` model + `AuditObserver` registration in the service provider.

---

## 7. Future vs past flows

- **Past (authoritative):** metering hook freezes real provider/model + applied rates + cost on the
  ledger at call time; subscription coverage forces €0 and tags the row. Queries read the frozen row;
  never re-priced. Fallback to config-resolution only when `provider == 'unknown'`.
- **Future (projection):** preflight estimate / what-if / forecast resolve via §B and may apply §C
  overhead %; subscription-covered providers project as €0 within their window.

---

## 8. Security & compliance

- OpenRouter key and any provider keys: **never** returned. Status exposes `has_openrouter_key` boolean
  only. Settings forms are write-only with explicit Replace/Clear (per RULES).
- Sanitized errors; network failures degrade to cache; no raw provider payloads in JSON.
- EU/multi-tenant aware; subscription windows are tenant-scopable.

---

## 9. Testing / Definition of Done

**Per-subtask DoD (every M8.x):** precise objective + implementation details + **guardrail tests** —
PHPUnit always; Vitest + **Playwright scenarios for ALL UI interactions** when the subtask touches
admin UI/UX (backend-only subtasks need no Vitest/Playwright). A subtask is **closed only** by this loop:

1. All local tests green (PHPUnit / Vitest / Playwright as applicable).
2. Local Copilot review: `copilot --autopilot --yolo -p "/review <FULL branch diff vs origin/main>"`
   (pass the **whole** branch diff, not just uncommitted files; if too large, write to a temp file and
   pass the file). Iterate until **zero comments**.
3. Only at green tests **and** zero local Copilot comments → **push**.
4. Open **PR into the working macro branch**; add **@copilot as reviewer** and confirm its review started.
5. Wait for **both** CI all-green **and** Copilot comments.
6. If all pass → **merge** PR. Otherwise fix broken tests + Copilot comments, **update `docs/LESSON.md`**
   with what was learned, push again, re-request Copilot review, repeat the loop.
7. Only when everything is green → subtask done → next.

**Cross-cutting per step:** keep `docs/PROGRESS.md` (dated, resume point) and `docs/LESSON.md`
(discoveries, gotchas, Copilot-fix learnings) current; **pass `LESSON.md` into every parallel subagent**
and re-read it when opening a new session.

Hermetic PHPUnit (bind in-memory sources; **never** hit the network mirror), covering:
- `OpenRouterPricingSource`: response normalization (string→float, cache keys), keyless vs keyed,
  graceful network failure, `synced_at` stamping.
- `PricingSourceManager`: ordering, `merged()` tagging, `syncAll()` counts.
- `PricingRegistry`: full resolution matrix — override wins; provider_source_map routing; freshest
  `synced_at` wins; env tiebreak; unknown-provider fallback.
- `SubscriptionWindow`: active-window match (open-ended, tenant/model scope, boundary dates) ⇒ €0 +
  `covered` status + `covered_by` tag; expired window ⇒ normal price.
- Overhead overlay applies to estimate paths only, never the ledger.
- Manual per-1M / EUR override → correct per-token + FX.
- Provenance frozen in `metadata` (rates, upstream, source_synced_at).
- `PricingController`: per-source status incl. `has_*`; `models()` source field + filter.

Gates: `composer validate --strict`, full PHPUnit green on PHP 8.3/8.4(/8.5), Pint. No network in tests.
Branch/PR loop per RULES (local `copilot /review` zero-comment → PR + Copilot reviewer → CI green → merge).

---

## 10. Admin impact / handoff (for `../laravel-ai-finops-admin` plan)

This spec emits the **API contract**; the admin repo runs its own brainstorming→plan→impl cycle.
Hand the admin plan these change points:

- **Settings screen:** source toggles + order + `default_winner`; per-provider authority map editor;
  OpenRouter key as **write-only** field showing `has_openrouter_key`; per-provider `fees.markup_pct`;
  **subscription windows CRUD** (provider, label, from/to, tenant/model scope, enable).
- **Pricing Registry screen:** `source` badge/column per model row; filter by source; **manual add**
  form with **unit (per-token / per-1M)** + **currency (EUR/USD)**; per-source **sync status** panel
  (`synced_at`, model count, enabled, `has_key`) replacing the single sync indicator.
- **Price Watcher screen:** add a `source` dimension to snapshots/comparisons.
- **Usage / Call-trace detail:** show `metadata.upstream_provider`, frozen `rate_input/output`,
  `covered_by` (subscription) and the `covered` status.

**API contract delta** (package side, all under `/api/ai-finops`):
- `GET pricing/sync/status` → `{ sources: [{name, enabled, synced_at, models, has_key}] }`.
- `GET pricing/models?source=` → rows gain `source`.
- `pricing/overrides` CRUD (manual prices) → accepts `unit` (`per_token|per_million`) + `currency`
  (+ optional `effective_from`, `note`). This is the **hand-entry mask for costs (e.g. regolo)**.
- New `…/subscription-windows` CRUD (provider, label, starts_at, ends_at, tenant_id?, model?, enabled,
  note) → the **hand-entry mask for canoni/subscriptions**.
- Settings snapshot adds `pricing.sources`, `provider_source_map`, `fees`, `has_openrouter_key`.

**Admin handoff deliverable:** after this spec is approved, create
`../laravel-ai-finops-admin/docs/superpowers/specs/2026-05-31-multi-source-pricing-admin-design.md`
seeded from §10, then run brainstorming→writing-plans in that repo. Guardrail: **Playwright E2E for
every new UI interaction** (per project DoD).

---

## 11. Milestone breakdown (M8 subtasks → subtask PRs into `feat/core-multisource-pricing`)

- **M8.1** `PricingSource::syncedAt()` + per-source `synced_at` stamping; update LiteLLM source + fixture.
- **M8.2** `OpenRouterPricingSource` (normalization, keyless/keyed, graceful failure) + tests.
- **M8.3** `PricingSourceManager` (ordering, merged, syncAll) + container wiring + tests.
- **M8.4** `PricingRegistry` multi-source resolution (map → freshest → tiebreak → fallback) + tests.
- **M8.5** Manual source upgrade + **hand-entry cost mask/API**: `unit` per-1M/EUR + `effective_from`/
  `note` on `PricingOverride`, overrides CRUD endpoints, FX + tests (regolo as the worked example).
- **M8.6** Subscription coverage windows (model+migration+audit, metering €0+tag, `CallStatus::Covered`,
  routing synergy) + tests.
- **M8.7** Overhead overlay (`fees.markup_pct`) on estimate/what-if/forecast paths + tests.
- **M8.8** Ledger provenance freeze (`upstream_provider`, `rate_*`, `source_synced_at`) + tests.
- **M8.9** API: per-source sync status, `models` source field/filter, subscription-windows CRUD,
  settings snapshot fields (`has_*`) + tests.
- **M8.10** Docs: update README ("always-fresh pricing" → multi-source + subscriptions + manual masks),
  `config/ai-finops.php` comments, and write the admin handoff doc (§10).
- **M8.11** Admin alignment: seed the admin handoff spec (§10) in `../laravel-ai-finops-admin`, then run
  its own brainstorming→writing-plans→impl loop (Playwright E2E for every UI interaction).
- **M8.12 (closeout)** Review `docs/LESSON.md` + everything learned during M8; create/strengthen
  `docs/RULES.md`, the `.claude/skills/laravel-ai-finops-plan` skill, and `AGENTS.md` with the new
  know-how (multi-source resolution, subscription windows, manual masks, OpenRouter/regolo gotchas).
- **M8.13 (release)** After the macro PR `feat/core-multisource-pricing` → `main` is merged and CI green:
  bump version, **tag `vX.X.X`**, and cut the **GitHub release** (public/irreversible → confirm version
  + timing with the user first, per PROGRESS M7.3 convention).

> **Macro/PR topology:** macro branch `feat/core-multisource-pricing` off `main`; each M8.x is a subtask
> PR into the macro branch (closure loop §9); finally one macro PR into `main`. Never fake the loop.

---

## 12. Risks / open questions

- **OpenRouter model id ↔ provider naming** differs from LiteLLM (`author/slug` vs `litellm_provider`).
  Resolution must normalize model keys; the `provider_source_map` mitigates cross-feed collisions.
- **Currency mixing** (regolo EUR vs USD feeds): rely on existing `FxConverter`; store row currency.
- **OpenRouter public list returned 343** (< marketing "300+/400+"): a key may widen it — `has_key` surfaces this.
- **Phase-2 endpoint ingestion** (per-provider 3–10× spread) deferred; schema/flag (`use_endpoints`) reserved.
