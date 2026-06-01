# Cost Resolution Cascade (M9) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox (`- [ ]`). Pass `docs/LESSON.md` to every subagent; re-read `docs/PROGRESS.md` + `docs/LESSON.md` at session start. Run tests with `php vendor/bin/phpunit` from **PowerShell**.

**Goal:** Price every call by the truest number available — actual billed cost → actual tokens × tariff → estimated tokens × tariff — recording the method and whether tokens were estimated, plus recover the provider cost that `laravel/ai` discards.

**Architecture:** A `CostResolutionService` runs the a→b→c cascade. Actual cost comes from an `ActualCostResolver` seam fed by a `RawResponseCapture` (a global `Http` response middleware that sniffs `usage.cost` before `laravel/ai` drops it). A `TokenEstimator` seam (heuristic, auto-upgraded to `yethee/tiktoken` when installed) covers case c. New ledger columns record method + estimated flag + billed cost. fal/media use a unit-cost resolver.

**Tech Stack:** PHP ^8.3, Laravel 13, Testbench/PHPUnit (hermetic), Pint. Optional `yethee/tiktoken`.

**Companion spec:** `docs/superpowers/specs/2026-06-01-cost-resolution-cascade-design.md` (read first).

---

## Per-task Definition of Done (every task)

Closure loop, never faked: (1) local tests green (`composer validate --strict`, `php vendor/bin/phpunit`, Pint); (2) local Copilot `/review <full branch diff vs origin/main>` → zero comments; (3) push; (4) PR into macro branch `feat/core-cost-cascade` + @copilot; (5) CI + Copilot green; (6) merge; else fix + update `docs/LESSON.md` + repeat; (7) update `docs/PROGRESS.md`. Macro PR → `main` at the end. **The plan's last task before release is a standalone full README audit (project rule).**

---

## File Structure

**Create:**
- `src/Contracts/ActualCostResolver.php` — seam: response → real billed cost (or null).
- `src/Contracts/TokenEstimator.php` — seam: text/messages → TokenUsage.
- `src/Pricing/Cost/RawResponseCapture.php` — scoped buffer of captured provider usage/cost.
- `src/Pricing/Cost/HttpUsageCaptureMiddleware.php` — global Http response middleware (factory).
- `src/Pricing/Cost/NullActualCostResolver.php` · `OpenRouterCostResolver.php` · `FalUnitCostResolver.php` · `ActualCostResolverManager.php`.
- `src/Pricing/Cost/HeuristicTokenEstimator.php` · `TiktokenTokenEstimator.php`.
- `src/Pricing/Cost/CostResolutionService.php` — the a→b→c cascade.
- `src/Pricing/Cost/Resolution.php` — value object {cost, billedCost, billedCurrency, method, tokens, tokensEstimated}.
- `src/Enums/CostMethod.php` — actual|computed|estimated|covered.
- `database/migrations/2026_06_02_000001_add_cost_method_to_ai_finops_usage_ledger_table.php`
- `database/migrations/2026_06_02_000002_add_unit_rate_to_ai_finops_pricing_overrides_table.php`
- Tests: `tests/Feature/TokenEstimatorTest.php`, `RawResponseCaptureTest.php`, `ActualCostResolverTest.php`, `CostResolutionCascadeTest.php`, `FalUnitCostTest.php`, `EstimateApiTest.php` (extend existing where natural).

**Modify:**
- `src/Metering/MeteringListener.php` — use `CostResolutionService`; pass prompt; write new fields.
- `src/Data/AiCallEnvelope.php` — carry `costMethod`, `tokensEstimated`, `billedCost`, `billedCurrency`; `toLedgerRow`.
- `src/Models/UsageRecord.php` — casts for new columns (verify).
- `src/Models/PricingOverride.php` — `unit_rate` + new `unit` values for media.
- `src/Http/Controllers/SettingsController.php` — `estimate()` accepts prompt text; snapshot adds estimator/actual_cost.
- `src/LaravelAiFinOpsServiceProvider.php` — bind seams + register capture middleware when enabled.
- `config/ai-finops.php` — `pricing.actual_cost` + `pricing.token_estimation`.
- `composer.json` — `suggest` `yethee/tiktoken`.
- `README.md`, `docs/PROGRESS.md`, `docs/LESSON.md`.

---

## Task 0: Macro branch + config + composer suggest

- [ ] **Step 1:** `git checkout main && git pull && git checkout -b feat/core-cost-cascade`
- [ ] **Step 2:** Add to the `pricing` block in `config/ai-finops.php` (spec §5):

```php
'actual_cost' => [
    'enabled'   => env('AI_FINOPS_ACTUAL_COST', false),
    'hosts'     => ['openrouter.ai'],
    'store_raw' => false,
    'openrouter' => ['generation_lookup' => false, 'credit_to_currency' => 1.0],
],
'token_estimation' => ['enabled' => true, 'expected_output_ratio' => 1.0],
```

- [ ] **Step 3:** In `composer.json` add a `suggest` entry: `"yethee/tiktoken": "Exact token counting for OpenAI / OpenAI-compatible models (FinOps cost estimation case c); heuristic fallback used otherwise."`
- [ ] **Step 4:** `git add -A && git commit -m "feat(cost): M9 config scaffold (actual_cost, token_estimation) + tiktoken suggest"`

---

## Task 1 (M9.1): Ledger columns + envelope fields

**Files:** migrations `…add_cost_method_to…usage_ledger`, `…add_unit_rate_to…pricing_overrides`; modify `AiCallEnvelope.php`; Test `CostResolutionCascadeTest.php` (later) — here a focused migration/envelope test.

- [ ] **Step 1: Migration 1** — add to the ledger table: `$table->string('cost_method', 16)->default('computed')->index();` `$table->boolean('tokens_estimated')->default(false);` `$table->decimal('billed_cost', 18, 8)->nullable();` `$table->string('billed_currency', 3)->nullable();` (use `Schema::connection($this->connection())->table(...)`, mirroring the M8 overrides migration).
- [ ] **Step 2: Migration 2** — add to `pricing_overrides`: `$table->decimal('unit_rate', 18, 12)->nullable();` (the per-second/per-image rate; `unit` column from M8 reused with new values).
- [ ] **Step 3: `CostMethod` enum** (`actual|computed|estimated|covered`).
- [ ] **Step 4: Extend `AiCallEnvelope`** — add constructor params `CostMethod $costMethod = CostMethod::Computed`, `bool $tokensEstimated = false`, `?float $billedCost = null`, `?string $billedCurrency = null`; include them in `toLedgerRow()` (`cost_method`, `tokens_estimated`, `billed_cost`, `billed_currency`) and `copyWith`/`fromArray`.
- [ ] **Step 5: Failing test** — build an envelope with method=estimated + tokensEstimated=true + billedCost, assert `toLedgerRow()` carries them; persist via `UsageRecord` and read back.
- [ ] **Step 6: Run green** + Pint. **Step 7: Commit** `feat(cost): ledger cost_method/tokens_estimated/billed_cost columns + envelope fields`.

---

## Task 2 (M9.2): TokenEstimator (heuristic + optional tiktoken)

**Files:** `src/Contracts/TokenEstimator.php`, `src/Pricing/Cost/HeuristicTokenEstimator.php`, `src/Pricing/Cost/TiktokenTokenEstimator.php`; bind in provider; Test `TokenEstimatorTest.php`.

- [ ] **Step 1: Contract**

```php
interface TokenEstimator
{
    /** Estimate token usage for a prompt (string or chat-messages array) on a model. */
    public function estimate(string|array $prompt, ?string $model = null): TokenUsage;
}
```

- [ ] **Step 2: Failing test** (heuristic):

```php
public function test_heuristic_estimates_by_chars_and_words(): void
{
    $est = new HeuristicTokenEstimator;
    // "one two three four" = 18 chars, 4 words → max(ceil(18/4)=5, ceil(4*1.3)=6) = 6
    $u = $est->estimate('one two three four');
    $this->assertSame(6, $u->input);
}
```

- [ ] **Step 3: Implement `HeuristicTokenEstimator`** — flatten messages array to text (concat `content`); `chars=mb_strlen`, `words=str_word_count`; `input = max((int)ceil($chars/4), (int)ceil($words*1.3))`; `output=0`.
- [ ] **Step 4: Implement `TiktokenTokenEstimator`** — constructor takes nothing; in `estimate`, pick encoding by model (`str_contains(model,'gpt-4o')||'o1'||'o3' → o200k_base` else `cl100k_base`), use `\Yethee\Tiktoken\EncoderProvider`. Guarded so the file only references the class at call time.
- [ ] **Step 5: Bind in provider** — `TokenEstimator` → tiktoken impl when `class_exists(\Yethee\Tiktoken\EncoderProvider::class)`, else heuristic.
- [ ] **Step 6: Test** the binding falls back to heuristic when tiktoken absent (it is absent in CI) — assert `app(TokenEstimator::class) instanceof HeuristicTokenEstimator`.
- [ ] **Step 7: Run green** + Pint. **Commit** `feat(cost): TokenEstimator (heuristic + optional tiktoken)`.

---

## Task 3 (M9.3): RawResponseCapture + Http capture middleware

**Files:** `src/Pricing/Cost/RawResponseCapture.php`, `src/Pricing/Cost/HttpUsageCaptureMiddleware.php`; register in provider; Test `RawResponseCaptureTest.php`.

- [ ] **Step 1: `RawResponseCapture`** (scoped) — holds `array $captures`; `push(array $c): void`; `drain(): array` (returns + clears); `sumCost(): array{cost,currency,tokens}` over current captures.
- [ ] **Step 2: Failing test** — feed two captures (multi-step), assert `drain()` sums cost and returns native tokens; second `drain()` is empty.
- [ ] **Step 3: Implement** capture value handling (sum `cost`; sum native tokens; keep currency).
- [ ] **Step 4: Middleware factory** `HttpUsageCaptureMiddleware::make(RawResponseCapture $cap, array $hosts): callable` returning a Guzzle response middleware: on each response, if the request host is in `$hosts`, parse the JSON body for `usage.cost`/`cost_details` + `id` + native tokens and `push` a capture; **never** read message content; always return the response untouched. (Read the body via `(string) $response->getBody()` then `rewind` the stream so it stays consumable.)
- [ ] **Step 5: Register** in `boot()` when `config('ai-finops.pricing.actual_cost.enabled')`: `Http::globalResponseMiddleware(HttpUsageCaptureMiddleware::make($this->app->make(RawResponseCapture::class), config('ai-finops.pricing.actual_cost.hosts', [])));`
- [ ] **Step 6: Test middleware** — build a fake PSR-7 response with `{"id":"gen-1","usage":{"cost":0.0123,"prompt_tokens":100,"completion_tokens":50}}` for host `openrouter.ai`, run the middleware, assert a capture was pushed with cost 0.0123; a non-listed host pushes nothing; body stays readable.
- [ ] **Step 7: Run green** + Pint. **Commit** `feat(cost): RawResponseCapture + opt-in Http usage/cost capture middleware`.

---

## Task 4 (M9.4): ActualCostResolver contract + manager + OpenRouter + Null

**Files:** `src/Contracts/ActualCostResolver.php`, `src/Pricing/Cost/{NullActualCostResolver,OpenRouterCostResolver,ActualCostResolverManager}.php`; bind in provider; Test `ActualCostResolverTest.php`.

- [ ] **Step 1: Contract + value**

```php
interface ActualCostResolver
{
    /** The provider's actual billed cost for this call, or null if unavailable. */
    public function resolve(AiCallEnvelope $call): ?ResolvedActualCost;
}
final readonly class ResolvedActualCost {
    public function __construct(public float $amount, public string $currency,
        public ?TokenUsage $tokens = null, public string $source = 'provider') {}
}
```

- [ ] **Step 2: Failing test** — with two OpenRouter captures present, `OpenRouterCostResolver->resolve($callWithProvider('openrouter'))` returns summed cost × `credit_to_currency`, native tokens; for provider `openai` returns null; with no captures returns null.
- [ ] **Step 3: Implement** `OpenRouterCostResolver` (constructor `RawResponseCapture $cap, Config $config`): only acts for OpenRouter-routed calls; `drain()` captures, sum; convert credits via `pricing.actual_cost.openrouter.credit_to_currency`; (generation lookup left as a config-gated TODO hook calling the OpenRouter `/generation` endpoint — implement the HTTP call guarded by `generation_lookup`). `NullActualCostResolver` returns null. `ActualCostResolverManager` picks a resolver by provider from `config('ai-finops.pricing.actual_cost.resolvers')` (default: openrouter→OpenRouter, fal→Fal) else Null.
- [ ] **Step 4: Bind** `ActualCostResolver` → manager (singleton) in provider.
- [ ] **Step 5: Run green** + Pint. **Commit** `feat(cost): ActualCostResolver seam + OpenRouter resolver (capture/credits)`.

---

## Task 5 (M9.5): CostResolutionService cascade + MeteringListener wiring

**Files:** `src/Pricing/Cost/{Resolution,CostResolutionService}.php`; modify `MeteringListener.php`; Test `CostResolutionCascadeTest.php`.

- [ ] **Step 1: `Resolution`** value object {`CostBreakdown $cost`, `?float $billedCost`, `?string $billedCurrency`, `CostMethod $method`, `TokenUsage $tokens`, `bool $tokensEstimated`}.
- [ ] **Step 2: Failing tests** — three cases:
  - (a) resolver returns actual cost → `method=actual`, `billedCost` set, `cost->total == billed`.
  - (b) no actual but usage tokens present → `method=computed`, `tokensEstimated=false`, cost = tokens×tariff.
  - (c) no actual, zero usage, prompt given → `method=estimated`, `tokensEstimated=true`, tokens from estimator.
- [ ] **Step 3: Implement** `CostResolutionService::resolve(AiCallEnvelope $call, TokenUsage $usage, string|array|null $prompt): Resolution` using `ActualCostResolver`, `PricingRegistry`+`CostCalculator`, `TokenEstimator` per spec §D. (Subscription coverage stays in `MeteringListener` after resolution: zero the cost + method `covered`, keep `billedCost`.)
- [ ] **Step 4: Wire `MeteringListener`** — inject `CostResolutionService`; `handleAgentPrompted` passes `$event->prompt`; `baseEnvelope` builds tokens then calls the service; map `Resolution` → envelope fields (cost, method, tokensEstimated, billedCost/currency) + existing provenance. Covered-window logic sets method `Covered`.
- [ ] **Step 5: Run green** (incl. existing MeteringCostTest) + Pint. **Commit** `feat(cost): CostResolutionService cascade (actual->computed->estimated) wired into metering`.

---

## Task 6 (M9.6): fal unit-cost resolver

**Files:** `src/Pricing/Cost/FalUnitCostResolver.php`; modify `PricingOverride.php`; Test `FalUnitCostTest.php`.

- [ ] **Step 1: `PricingOverride`** — `unit` may now be `per_second|per_image|per_megapixel|per_request`; expose `unit_rate`. Add a helper `unitRate(): ?float`.
- [ ] **Step 2: Failing test** — a `fal` override with `unit=per_second, unit_rate=0.0005`; `FalUnitCostResolver->resolve($call)` where the call metadata carries `inference_time=8.0` → cost `0.004`. Per-image unit with an output count works too.
- [ ] **Step 3: Implement** `FalUnitCostResolver` reading the matching `PricingOverride` for the model/provider and the call's `metadata` (inference_time / image count) → `ResolvedActualCost`. Register it in the manager for `fal`/`fal_ai`.
- [ ] **Step 4: Run green** + Pint. **Commit** `feat(cost): fal unit-cost resolver (per-second/image/megapixel)`.

---

## Task 7 (M9.7): API — estimate from prompt + usage rows + settings

**Files:** modify `SettingsController.php`, `UsageController.php` (response shape), `routes/api.php` (estimate already exists); Tests `EstimateApiTest.php` + extend `UsageApiTest`/`SettingsApiTest`.

- [ ] **Step 1: Failing tests** —
  - `POST diagnostics/estimate` with `{prompt:'...', model:'gpt-4o'}` → `{tokens, cost, method:'estimated', tokens_estimated:true, currency}`.
  - `GET usage` rows include `cost_method`, `tokens_estimated`, `billed_cost`.
  - `GET settings` snapshot includes `actual_cost.enabled` + an `estimator` field (`heuristic`|`tiktoken`).
- [ ] **Step 2: Implement** — `estimate()` accepts `prompt`/`messages` + `model` (+ provider), uses `TokenEstimator` + `PricingRegistry`+`CostCalculator`, returns method=estimated. Usage serializer adds the columns. Settings snapshot adds the indicators (booleans/labels only — no secrets).
- [ ] **Step 3: Run green** + Pint. **Commit** `feat(api): estimate-from-prompt + cost method/billed on usage + settings estimator status`.

---

## Task 8 (M9.8): Docs — full README audit & update (standalone, mandatory)

**Files:** `README.md`, `docs/PROGRESS.md`, `docs/LESSON.md`.

- [ ] **Step 1:** Audit the WHOLE README (per project rule). Update **every** relevant section:
  - **Hero / Why-different:** add a bullet on the **a→b→c cost cascade** and a **WOW point: we overcame
    a current `laravel/ai` limitation** — it returns tokens only and drops the provider's real cost; we
    recover the actual billed amount (e.g. OpenRouter `usage.cost`) via a global `Http` capture.
  - **How it works (flow):** post-flight resolves cost by actual → tokens×tariff → estimated, recording
    method + estimated flag + billed cost.
  - **Features table:** new row "Cost accuracy: actual billed cost when the provider returns it, else
    tokens×tariff, else estimated tokens (flagged); per-call `cost_method`".
  - **Configuration:** document `pricing.actual_cost` (+ OpenRouter generation lookup) and
    `pricing.token_estimation`; note the **optional `yethee/tiktoken`** package for exact counts.
  - **API overview:** `diagnostics/estimate` (prompt text), usage `cost_method`/`tokens_estimated`/`billed_cost`.
- [ ] **Step 2:** Grep for stale phrasing; ensure Configuration + API match `config/ai-finops.php` + `routes/api.php`.
- [ ] **Step 3:** Update PROGRESS (resume point) + LESSON (laravel/ai capture mechanism, tiktoken optional, cascade).
- [ ] **Step 4: Commit** `docs(M9): README cascade + overcoming laravel/ai cost limitation; config/API; LESSON/PROGRESS`.

---

## Task 9 (M9.9): Admin handoff

- [ ] Seed `../laravel-ai-finops-admin/docs/superpowers/specs/2026-06-01-cost-cascade-admin-design.md`: method badge (actual/computed/estimated/covered) + "estimated tokens" marker on Usage explorer & Call/Trace; billed-vs-computed display; Diagnostics estimate-from-prompt screen; Settings estimator/actual-cost toggles + fal unit rates. Then run its own brainstorm→plan→impl (Vitest + Playwright per interaction). README audit task at the end (admin rule).

---

## Task 10 (M9.10): Macro PR + release

- [ ] Macro PR `feat/core-cost-cascade` → `main`; @copilot; CI + Copilot green; merge.
- [ ] Confirm version with user (public/irreversible). M9 is additive → minor bump `v1.2.0`. Tag + GitHub release.

---

## Self-Review (done)

- **Spec coverage:** §A→T3; §B→T4; §C→T2; §D→T5; §E→T5; §F→T6; §G→T1; §5 config→T0; §6 API/admin→T7/T9; README WOW (§8/M9.8)→T8; release→T10. No gaps.
- **Placeholders:** the generation-lookup HTTP call is specified as config-gated in T4; test matrices in T5 are fully determined by the spec cascade. No TBDs.
- **Type consistency:** `CostMethod{Actual,Computed,Estimated,Covered}`, `ResolvedActualCost{amount,currency,tokens,source}`, `Resolution{cost,billedCost,billedCurrency,method,tokens,tokensEstimated}`, `TokenEstimator::estimate(string|array,?string)`, `ActualCostResolver::resolve(AiCallEnvelope)`, `RawResponseCapture::{push,drain,sumCost}` used consistently across tasks.
