# Design Spec — M9: Cost Resolution Cascade (actual → tokens×tariff → estimated) + token estimation + fal unit cost

- **Date:** 2026-06-01
- **Status:** Design — pending user review
- **Package:** `padosoft/laravel-ai-finops` (admin companion: `../laravel-ai-finops-admin`)
- **Milestone:** M9 (builds on M8 multi-source pricing; additive)

---

## 1. Motivation

Today the metering hook always prices a call as **actual tokens (from `laravel/ai`'s normalized
`Usage`) × our tariff**. That is correct as a default, but the user wants a **priority cascade** that
prefers the *truest* number available, records *how* the cost was derived, and never silently invents
numbers:

1. **(a) actual billed cost** from the provider response, when present — that is the real invoiced
   amount (unless the call is under flat-rate subscription coverage → €0 per M8).
2. **(b) actual tokens × our tariff** — when the response carries token usage but no cost (the common
   case, and today's default).
3. **(c) estimated tokens × our tariff** — when the response carries neither cost nor usage: estimate
   the tokens, then price. If we have nothing to estimate from, a token estimator must still produce a
   best-effort number, clearly flagged.

Every ledger row must record **which method (a/b/c) produced the cost** and **whether the tokens were
actual or estimated**, so historical analysis can distinguish billed truth from estimate.

This also serves two related goals: (1) **simulate/estimate** a call's cost before sending it; and
(2) **forecast** future spend from tariff + trend (the existing forecaster, fed correct prices).

---

## 2. Research findings (verified 2026-06-01)

### 2.1 Who returns actual cost in the response

| Provider | Cost in response? | Path | Token fields | Cost lookup endpoint |
|---|---|---|---|---|
| **OpenRouter** | ✅ **yes** (credits) | `usage.cost` (+ `usage.cost_details`) | prompt/completion/total, `prompt_tokens_details.cached_tokens`/`cache_write_tokens`, `completion_tokens_details.reasoning_tokens` | ✅ `GET /api/v1/generation?id=` → `total_cost` (USD), `native_tokens_*` |
| **OpenAI** | ❌ tokens only | — | `usage.*` (+ details) | Costs API org/**daily** only (not per-call) |
| **Anthropic** | ❌ tokens only | — | `usage.input/output/cache_*` | `count_tokens` (tokens only) |
| **Gemini** | ❌ tokens only | — | `usageMetadata.*` | `countTokens` (tokens only) |
| **regolo.ai** | ❌ tokens only | — | OpenAI-compatible `usage.*` | none |
| **fal.ai** | ❌ no tokens, no cost | — | none (per image/sec/MP) | `metrics.inference_time` + `POST /v1/models/pricing/estimate` |

### 2.2 The `laravel/ai` constraint (verified in `vendor/`)

- `laravel/ai` normalizes every response to a provider-agnostic **`Usage`** (token counts only:
  `promptTokens`, `completionTokens`, `cacheWriteInputTokens`, `cacheReadInputTokens`,
  `reasoningTokens`) + **`Meta`** (`provider`, `model`, `citations`). **No cost field, no raw payload**
  is retained (`vendor/laravel/ai/src/Responses/Data/{Usage,Meta}.php`, `TextResponse.php`).
- Its OpenRouter gateway `extractUsage()` reads tokens and **discards `usage.cost`**
  (`vendor/laravel/ai/src/Gateway/OpenRouter/Concerns/ParsesTextResponses.php:320`).
- Events (`AgentPrompted{invocationId, prompt, response}`) carry only the normalized response.
- **By design** (clean provider-agnostic API), not a bug.

**Extension point (how we recover actual cost without forking):** the gateway builds its client via
Laravel's **`Http` facade** (`CreatesOpenRouterClient`: `Http::baseUrl(...)`). Laravel's
`Http::globalResponseMiddleware()` therefore sees the **raw provider JSON** before normalization
discards it. We register a global response middleware (only when the feature is enabled) that captures
`usage.cost` + the provider response `id` + native token counts for cost-bearing hosts, stashes them
request-scoped, and correlates them to the metered call. Subclassing/replacing the gateway is possible
but invasive (forces the host to use our driver) → kept as a documented non-default fallback.

### 2.3 Token estimation in PHP

- Exact for OpenAI / OpenAI-compatible: **`yethee/tiktoken`** (`cl100k_base`, `o200k_base`). Optional —
  no first-party PHP tokenizer exists for Claude/Gemini (use tiktoken as a ±5–10% proxy).
- Heuristic fallback: `max(chars/4, words×1.3)`; ±10–20% on English prose, worse on code/non-Latin.
- Network exact (free): Anthropic `count_tokens`, Gemini `countTokens` (out of scope for the default
  path — they add a round-trip; reserved as an optional estimator strategy later).

---

## 3. Goals / Non-goals

**Goals**
- A `CostResolutionService` implementing the **a → b → c** cascade, returning the cost, the **method**,
  the tokens, a **`tokens_estimated`** flag, and the **billed cost** when known.
- An `ActualCostResolver` seam (per-provider) with a built-in **OpenRouter** resolver (capture +
  optional `/generation` confirm) and a **fal unit-cost** resolver; default Null.
- A `RawResponseCapture` + opt-in global `Http` response middleware to recover cost/raw `usage` from
  cost-bearing providers (OpenRouter), never storing message content.
- A `TokenEstimator` seam: heuristic built-in; auto-use **optional** `yethee/tiktoken` when installed.
- **Ledger columns** `cost_method`, `tokens_estimated`, `billed_cost`, `billed_currency` (additive).
- Preflight estimate from prompt text (goal 1); forecaster fed correct prices (goal 2).
- API + admin surfacing; README (mandatory final task).

**Non-goals**
- Anthropic/Gemini network `count_tokens` estimators (reserved; heuristic/tiktoken cover the default).
- Forking or replacing `laravel/ai`'s providers/gateways by default.
- Re-pricing history (the ledger stays frozen truth, as in M8).
- Full fal media-billing matrix beyond inference-time / per-image / manual unit rates.

---

## 4. Architecture

### § A — `RawResponseCapture` + global Http middleware (recover actual cost)
- `RawResponseCapture` — **scoped** service (reset per request/job, like `TraceContext`). Holds an
  ordered list of captures `{host, id, cost, currency, native_prompt, native_completion, native_cached,
  native_reasoning, usage_raw}` for the current scope.
- A global response middleware, registered in `boot()` **only when**
  `config('ai-finops.pricing.actual_cost.enabled')` is true, inspects responses whose host matches a
  configurable allow-list (default `openrouter.ai`). It parses `usage.cost`/`cost_details` + `id` +
  native tokens and pushes a capture. It **never** reads or stores message content (PII/secrets rule);
  only the `usage`/`cost` block (+ id) is retained, optionally surfaced as `metadata.usage_raw`.
- Correlation: the metering hook, when resolving a call, **drains** the captures recorded in the
  current scope since the last metered event and sums their `cost` (handles `laravel/ai` multi-step
  tool loops, which combine usage across steps). FIFO within the scope; concurrency-safe because the
  service is request/job-scoped.

### § B — `ActualCostResolver` (per-provider seam)
- Contract: `resolve(AiCallEnvelope $call, RawResponseCapture $capture): ?ResolvedActualCost` where
  `ResolvedActualCost{ amount: float, currency: string, tokens?: TokenUsage, source: string }`. Null =
  no actual cost available → caller falls through to (b).
- A small **manager** maps provider → resolver (mirrors `PricingSourceManager`), config
  `pricing.actual_cost.resolvers`. Built-ins:
  - `OpenRouterCostResolver` — reads summed captures for the call; converts credits→currency (1:1 by
    default, configurable); if `pricing.actual_cost.openrouter.generation_lookup` is on and a capture
    `id` exists, optionally confirms via `GET /api/v1/generation?id=` (`total_cost` USD, native tokens).
  - `FalUnitCostResolver` — for `fal`/media providers: cost from `metrics.inference_time` × per-second
    rate, or per-image/per-megapixel, from manual unit overrides (see § F) or the fal estimate endpoint;
    returns a unit-priced amount with `tokens = null`.
- Default binding: `NullActualCostResolver` (returns null) → cascade behaves exactly like today (b).

### § C — `TokenEstimator` (seam)
- Contract: `estimate(string|array $promptOrMessages, string $model): TokenUsage` (input tokens; output
  unknown pre-flight → 0, or a configurable expected-output ratio for preflight estimates).
- `HeuristicTokenEstimator` (default): `max(ceil(chars/4), ceil(words×1.3))`, with an optional
  per-content multiplier. Zero dependencies.
- `TiktokenTokenEstimator` — bound **automatically** when `class_exists(\Yethee\Tiktoken\EncoderProvider::class)`
  (the optional `yethee/tiktoken` package); picks `o200k_base`/`cl100k_base` by model. Exact for
  OpenAI/compatible, proxy elsewhere.
- Service-provider binds `TokenEstimator` to the tiktoken impl when available, else heuristic.

### § D — `CostResolutionService` (the cascade)
Single entry point used by the metering hook (post-flight) and the estimate endpoint (pre-flight):

```
resolve(call, usage, promptText?) -> Resolution{
    cost: CostBreakdown, billedCost: ?float, billedCurrency: ?string,
    method: 'actual'|'computed'|'estimated', tokens: TokenUsage, tokensEstimated: bool
}
```
1. **(a)** `actual = ActualCostResolver->resolve(...)`. If non-null → `method=actual`,
   `billedCost=actual.amount`, tokens = actual.tokens ?? usage; `cost` = billed amount as a
   `CostBreakdown` (total = billed; input/output split kept from tokens×tariff for analytics).
2. **(b)** else if `usage` has any non-zero token → price via `PricingRegistry`+`CostCalculator`;
   `method=computed`, `tokensEstimated=false`.
3. **(c)** else → `tokens = TokenEstimator->estimate(promptText, model)`; price via tariff;
   `method=estimated`, `tokensEstimated=true`.
- **Subscription coverage (M8)** is applied AFTER: if covered, `cost` is zeroed and status `covered`,
  but `method` and the would-be `billedCost`/rates are still recorded (so "value consumed" is analyzable).
- Unpriced/unknown inputs return a zero cost **with a message + method**, never a fabricated number
  (existing rule).

### § E — MeteringListener integration
- `handleAgentPrompted` passes `$event->prompt` through so the service can estimate (case c) from the
  prompt text when usage is absent. Embeddings/stream paths pass their input similarly.
- The listener writes the new ledger fields (§ G) from the `Resolution`.

### § F — fal / unit-priced providers
- `PricingOverride` (M8 manual source) gains an optional **unit dimension** for media: reuse the
  `unit` column with new values `per_second` | `per_image` | `per_megapixel` | `per_request`, plus a
  `unit_rate` (the manual rate). `FalUnitCostResolver` reads these to price from `metrics.inference_time`
  / output counts. This keeps fal pricing operator-editable (no fal price API).

### § G — Ledger columns (additive migration, backward-compatible)
- `cost_method` string(16) default `computed` (`actual|computed|estimated|covered`), indexed.
- `tokens_estimated` boolean default false.
- `billed_cost` decimal(18,8) nullable; `billed_currency` char(3) nullable (the provider's real charge
  when known, distinct from `cost_total`).
- Existing M8 `metadata` provenance (price_source, rates, synced_at, upstream, covered_by) is retained;
  add `usage_raw` (the captured usage/cost block) when actual-cost capture is on.

---

## 5. Config (additive to the `pricing` block)

```php
'actual_cost' => [
    'enabled'   => env('AI_FINOPS_ACTUAL_COST', false), // register the Http capture middleware
    'hosts'     => ['openrouter.ai'],                    // hosts to sniff for usage.cost
    'store_raw' => false,                                // also stash the usage/cost block in metadata
    'openrouter' => [
        'generation_lookup' => false,                    // confirm via GET /generation?id= (+1 HTTP)
        'credit_to_currency' => 1.0,                     // OpenRouter credits → base currency
    ],
],
'token_estimation' => [
    'enabled'          => true,                          // case (c) fallback
    'expected_output_ratio' => 1.0,                      // preflight: assume output ≈ input × ratio
],
```

`TokenEstimator` auto-upgrades to tiktoken when `yethee/tiktoken` is installed — no config needed.

---

## 6. API & admin impact

**Core API**
- `usage` / `usage/{id}` / `usage/{traceId}/trace` rows gain `cost_method`, `tokens_estimated`,
  `billed_cost`, `billed_currency`.
- `diagnostics/estimate` accepts `prompt` (text) or `messages` + `model` (+ provider) → returns
  `{tokens, cost, method:'estimated', currency, tokens_estimated:true}` using the `TokenEstimator`.
- `settings` snapshot adds `actual_cost.enabled`, `token_estimation`, and an `estimator` indicator
  (`tiktoken` present vs `heuristic`).

**Admin (handoff → its own plan)**
- Usage explorer + Call/Trace: badge for **method** (actual / computed / estimated / covered) and an
  **"estimated tokens"** marker; show `billed_cost` when present alongside computed cost.
- Diagnostics/estimator screen: estimate a cost from pasted prompt text; show which estimator is active.
- Settings: toggle actual-cost capture (+ OpenRouter generation lookup), show estimator status, edit
  fal unit rates (per_second/per_image/...).

---

## 7. Definition of Done

Per-subtask DoD + closure loop exactly as M8 (PHPUnit always; Vitest + **Playwright for every UI
interaction** in the admin; local Copilot `/review` zero-comments → push → PR → @copilot → CI +
Copilot green → merge). Hermetic tests: fake `Http`, bind in-memory resolvers/estimators; never hit
the network.

Test focus: cascade selects a/b/c correctly and records method + flags; OpenRouter capture middleware
extracts `usage.cost`/`id` and correlates (incl. multi-step sum); credits→currency; generation lookup
toggle; heuristic estimator math; tiktoken auto-binding when present (skipped when absent); fal unit
pricing from inference_time/per-image; subscription coverage still zeroes but records method/billed;
ledger columns persist + are queryable; estimate endpoint from prompt text; settings/estimator status;
secrets/PII never captured.

---

## 8. Milestone breakdown (M9 subtasks → PRs into `feat/core-cost-cascade`)

- **M9.1** Ledger migration + envelope/`UsageRecord` fields (`cost_method`, `tokens_estimated`,
  `billed_cost`, `billed_currency`) + tests.
- **M9.2** `TokenEstimator` contract + `HeuristicTokenEstimator` + auto-bind `TiktokenTokenEstimator`
  (optional `yethee/tiktoken`) + tests.
- **M9.3** `RawResponseCapture` (scoped) + opt-in global `Http` response middleware (OpenRouter
  `usage.cost`/`id`/native tokens; no message content) + tests.
- **M9.4** `ActualCostResolver` contract + manager + `OpenRouterCostResolver` (capture + optional
  generation lookup) + `NullActualCostResolver` default + tests.
- **M9.5** `CostResolutionService` cascade (a→b→c, subscription overlay, method/flags) + wire
  `MeteringListener` (pass prompt) + tests.
- **M9.6** fal unit-cost: `PricingOverride` unit/unit_rate extension + `FalUnitCostResolver` + tests.
- **M9.7** API: usage rows + `diagnostics/estimate` from prompt + settings estimator/actual-cost
  indicators + tests.
- **M9.8** Docs: **full README audit & update** (cascade a/b/c, optional `yethee/tiktoken`, actual-cost
  capture mechanism, new columns, fal unit pricing) — standalone final task (project rule).
- **M9.9** Admin alignment: seed handoff spec in the admin repo (method badges, estimator screen,
  fal rates, billed vs computed) → its own brainstorm→plan→impl (Playwright per interaction).
- **M9.10 (release)** macro PR → main, CI+Copilot green, confirm version with user → tag + release.

> **README rule:** every plan ends with a standalone README audit task (M9.8), last before release.

---

## 9. Risks / open questions

- **Capture↔call correlation** under heavy concurrency (Octane/queue): mitigated by the request/job
  scope + FIFO drain; documented limitation for exotic interleavings. Generation-lookup is the exact
  (heavier) confirm.
- **OpenRouter credits vs USD**: `usage.cost` is credits; `credit_to_currency` (default 1.0) maps it;
  the `/generation` endpoint gives authoritative USD `total_cost` when lookup is enabled.
- **tiktoken accuracy for non-OpenAI**: proxy ±5–10%; acceptable for estimates, flagged as estimated.
- **fal billing variety**: covered via manual unit rates + inference_time; exact per-model matrices are
  out of scope.
