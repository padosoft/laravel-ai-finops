# Multi-Source Pricing (M8) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **Pass `docs/LESSON.md` into every subagent.** Re-read `docs/PROGRESS.md` + `docs/LESSON.md` at the start of every session.

**Goal:** Add OpenRouter + a first-class manual price source (regolo.ai) alongside LiteLLM, resolve per-provider "who bills you" → freshest-sync → env tiebreak, model flat-rate subscription coverage (€0 windows) and per-provider overhead %, freeze richer cost provenance on the ledger, and expose hand-entry masks/APIs — all config-toggled.

**Architecture:** `PricingSource` implementations (LiteLLM, OpenRouter, manual-DB) behind a `PricingSourceManager`; `PricingRegistry` resolves override → `provider_source_map` → freshest `syncedAt()` → env `default_winner`. Subscription windows force covered calls to €0 in the metering hook; overhead % applies only to forward estimates. The immutable ledger stays the truth for past spend.

**Tech Stack:** PHP ^8.3, Laravel 13, Testbench/PHPUnit (hermetic, no network), Pint. Admin (separate repo): React+Vite+Tailwind, Vitest, Playwright.

**Companion spec:** `docs/superpowers/specs/2026-05-31-multi-source-pricing-design.md` (read it first).

---

## Per-task Definition of Done (applies to EVERY task below)

A task is **closed only** when this loop completes — never fake it:

1. All local tests green: `composer validate --strict` + `vendor/bin/phpunit` (PHP 8.3/8.4; 8.5 experimental) + Pint. Add Vitest + **Playwright scenarios for ALL UI interactions** for any admin-UI task.
2. Local Copilot review: `copilot --autopilot --yolo -p "/review <FULL branch diff vs origin/main>"` (whole branch diff; if too large, write to a temp file and pass the file). Iterate to **zero comments**.
3. Push only at green tests **and** zero local Copilot comments.
4. Open PR into the macro branch `feat/core-multisource-pricing`; add **@copilot** reviewer; confirm its review started.
5. Wait for **both** CI all-green **and** Copilot comments.
6. Pass → merge. Else: fix tests + Copilot comments, **update `docs/LESSON.md`** with learnings, push, re-request review, repeat.
7. Update `docs/PROGRESS.md` (dated, resume point) and tick the checkboxes. Then next task.

**Macro/PR topology:** branch `feat/core-multisource-pricing` off `main`; each task = a subtask PR into the macro branch; finally one macro PR into `main`.

---

## File Structure

**Create:**
- `src/Pricing/OpenRouterPricingSource.php` — OpenRouter live API → LiteLLM-style attr map.
- `src/Pricing/ManualPricingSource.php` — adapts `PricingOverride` rows into a `PricingSource` (`manual`).
- `src/Pricing/PricingSourceManager.php` — enabled+ordered sources, merged catalog, syncAll.
- `src/Models/SubscriptionWindow.php` — flat-rate coverage windows (canoni).
- `src/Http/Controllers/SubscriptionWindowController.php` — CRUD (hand-entry mask for canoni).
- `database/migrations/2026_06_01_000001_create_ai_finops_subscription_windows_table.php`
- `database/migrations/2026_06_01_000002_add_unit_to_ai_finops_pricing_overrides_table.php`
- Tests: `tests/Feature/OpenRouterPricingSourceTest.php`, `tests/Feature/PricingSourceManagerTest.php`,
  `tests/Feature/MultiSourceResolutionTest.php`, `tests/Feature/ManualPricingSourceTest.php`,
  `tests/Feature/SubscriptionCoverageTest.php`, `tests/Feature/OverheadOverlayTest.php`,
  `tests/Feature/LedgerProvenanceTest.php`, `tests/Feature/SubscriptionWindowApiTest.php`.

**Modify:**
- `src/Contracts/PricingSource.php` — add `syncedAt(): ?DateTimeInterface`.
- `src/Pricing/LiteLLMPricingSource.php` — stamp `synced_at`; implement `syncedAt()`.
- `src/Pricing/PricingRegistry.php` — multi-source resolution via the manager.
- `src/Pricing/ModelPrice.php` — carry optional `syncedAt`/`upstreamProvider` (provenance).
- `src/Pricing/CostCalculator.php` — `withOverhead()` for estimate paths.
- `src/Models/PricingOverride.php` — `unit`/`effective_from`/`note`; per-1M→per-token in `toModelPrice()`.
- `src/Http/Controllers/PricingController.php` — per-source `syncStatus()`, `models()` source field/filter, override `unit`.
- `src/Metering/MeteringListener.php` — subscription €0 coverage + freeze provenance in metadata.
- `src/Enums/CallStatus.php` — add `Covered`.
- `src/Routing/RoutingEngine.php` — treat covered providers as effectively-free.
- `src/LaravelAiFinOpsServiceProvider.php` — bind manager; observe `SubscriptionWindow`.
- `tests/Support/ArrayPricingSource.php` — implement `syncedAt()`.
- `config/ai-finops.php` — new `pricing` block (sources, map, default_winner, openrouter, fees).
- `routes/api.php` — subscription-windows CRUD routes.
- `README.md`, `docs/PROGRESS.md`, `docs/LESSON.md`, `docs/RULES.md`, `AGENTS.md`, the plan skill.

---

## Task 0: Macro branch + config scaffold

**Files:** Modify `config/ai-finops.php`; create the branch.

- [ ] **Step 1: Branch off main**

Run: `git checkout main && git pull && git checkout -b feat/core-multisource-pricing`

- [ ] **Step 2: Replace the `pricing` config block** with the §5 block from the spec (sources, default_winner, litellm, openrouter{enabled,url,key,allow_keyless,use_endpoints}, sync_cron, provider_source_map, fees, discounts). Keep `overrides_win => true`.

- [ ] **Step 3: Commit**

```bash
git add config/ai-finops.php && git commit -m "feat(pricing): multi-source config scaffold (sources, map, openrouter, fees)"
```

---

## Task 1 (M8.1): `syncedAt()` on the source contract + LiteLLM stamping

**Files:** Modify `src/Contracts/PricingSource.php`, `src/Pricing/LiteLLMPricingSource.php`, `tests/Support/ArrayPricingSource.php`; Test `tests/Feature/LiteLLMPricingSourceTest.php`.

- [ ] **Step 1: Failing test** — add to `LiteLLMPricingSourceTest`:

```php
public function test_sync_stamps_synced_at(): void
{
    Http::fake(['*' => Http::response(['gpt-4o' => ['input_cost_per_token' => 2.5e-6]], 200)]);
    $src = $this->app->make(\Padosoft\LaravelAiFinOps\Pricing\LiteLLMPricingSource::class);
    $this->assertNull($src->syncedAt());
    $src->sync();
    $this->assertNotNull($src->syncedAt());
}
```

- [ ] **Step 2: Run, expect FAIL** (`syncedAt` undefined): `vendor/bin/phpunit --filter test_sync_stamps_synced_at`

- [ ] **Step 3: Add to the interface**

```php
// src/Contracts/PricingSource.php  (inside interface)
public function syncedAt(): ?\DateTimeInterface;
```

- [ ] **Step 4: Implement in LiteLLM source** — add a `SYNCED_AT_KEY = self::CACHE_KEY.':synced_at'`; in `sync()` on success `('Asia'? no)` write `$this->cache->forever(self::SYNCED_AT_KEY, now()->toIso8601String());`; add:

```php
public function syncedAt(): ?\DateTimeInterface
{
    $at = $this->cache->get(self::CACHE_KEY.':synced_at');
    return is_string($at) ? \Carbon\CarbonImmutable::parse($at) : null;
}
```

- [ ] **Step 5: Implement in `ArrayPricingSource`** (test fixture): return `now()` (or a settable value).

- [ ] **Step 6: Run tests green** + Pint. **Step 7: Commit** `feat(pricing): PricingSource::syncedAt + LiteLLM stamping`. Then run the closure loop.

---

## Task 2 (M8.2): `OpenRouterPricingSource`

**Files:** Create `src/Pricing/OpenRouterPricingSource.php`; Test `tests/Feature/OpenRouterPricingSourceTest.php`.

- [ ] **Step 1: Failing test** (hermetic, faked HTTP):

```php
public function test_normalizes_openrouter_models_to_litellm_attr_map(): void
{
    Http::fake(['*models' => Http::response(['data' => [[
        'id' => 'meta-llama/llama-3.3-70b-instruct',
        'top_provider' => ['provider' => 'deepinfra'],
        'pricing' => ['prompt' => '0.0000001', 'completion' => '0.00000032', 'input_cache_read' => '0.00000004'],
    ]]], 200)]);
    config()->set('ai-finops.pricing.openrouter.enabled', true);
    config()->set('ai-finops.pricing.openrouter.allow_keyless', true);

    $src = $this->app->make(\Padosoft\LaravelAiFinOps\Pricing\OpenRouterPricingSource::class);
    $src->sync();
    $all = $src->all();

    $this->assertArrayHasKey('meta-llama/llama-3.3-70b-instruct', $all);
    $attr = $all['meta-llama/llama-3.3-70b-instruct'];
    $this->assertEqualsWithDelta(1e-7, $attr['input_cost_per_token'], 1e-12);
    $this->assertEqualsWithDelta(3.2e-7, $attr['output_cost_per_token'], 1e-12);
    $this->assertEqualsWithDelta(4e-8, $attr['cache_read_input_token_cost'], 1e-12);
    $this->assertSame('openrouter', $src->name());
}

public function test_disabled_without_key_when_keyless_false(): void
{
    config()->set('ai-finops.pricing.openrouter.enabled', true);
    config()->set('ai-finops.pricing.openrouter.allow_keyless', false);
    config()->set('ai-finops.pricing.openrouter.key', null);
    $src = $this->app->make(\Padosoft\LaravelAiFinOps\Pricing\OpenRouterPricingSource::class);
    $this->assertSame(0, $src->sync());
    $this->assertSame([], $src->all());
}
```

- [ ] **Step 2: Run, expect FAIL** (class missing).

- [ ] **Step 3: Implement** mirroring `LiteLLMPricingSource` structure (constructor `Http $http, Cache $cache, Config $config`; `CACHE_KEY='ai-finops:pricing:openrouter'`):

```php
public function sync(): int
{
    if (! $this->enabled()) { return 0; }
    $url = (string) $this->config->get('ai-finops.pricing.openrouter.url', 'https://openrouter.ai/api/v1/models');
    $key = $this->config->get('ai-finops.pricing.openrouter.key');
    try {
        $req = $this->http->acceptJson();
        if (is_string($key) && $key !== '') { $req = $req->withToken($key); }
        $response = $req->get($url);
    } catch (\Throwable) { return 0; }
    if (! $response->successful()) { return 0; }
    $rows = $response->json('data');
    if (! is_array($rows)) { return 0; }

    $map = [];
    foreach ($rows as $m) {
        if (! isset($m['id'])) { continue; }
        $p = $m['pricing'] ?? [];
        $map[$m['id']] = array_filter([
            'input_cost_per_token' => isset($p['prompt']) ? (float) $p['prompt'] : null,
            'output_cost_per_token' => isset($p['completion']) ? (float) $p['completion'] : null,
            'cache_read_input_token_cost' => isset($p['input_cache_read']) ? (float) $p['input_cache_read'] : null,
            'cache_creation_input_token_cost' => isset($p['input_cache_write']) ? (float) $p['input_cache_write'] : null,
            'litellm_provider' => $m['top_provider']['provider'] ?? explode('/', (string) $m['id'])[0] ?? null,
            'mode' => 'chat',
        ], static fn ($v) => $v !== null);
    }
    $this->cache->forever(self::CACHE_KEY, $map);
    $this->cache->forever(self::CACHE_KEY.':synced_at', now()->toIso8601String());
    return count($map);
}

private function enabled(): bool
{
    if (! (bool) $this->config->get('ai-finops.pricing.openrouter.enabled', false)) { return false; }
    $key = $this->config->get('ai-finops.pricing.openrouter.key');
    return (bool) $this->config->get('ai-finops.pricing.openrouter.allow_keyless', true) || (is_string($key) && $key !== '');
}
```

`all()` + `syncedAt()` mirror LiteLLM; `name()` returns `'openrouter'`.

- [ ] **Step 4: Run green** + Pint. **Step 5: Commit** `feat(pricing): OpenRouterPricingSource`. Closure loop.

---

## Task 3 (M8.5 — moved early): `ManualPricingSource` + per-1M/EUR override

**Files:** Modify `src/Models/PricingOverride.php`; create `src/Pricing/ManualPricingSource.php`, migration `…add_unit_to…pricing_overrides`; Test `tests/Feature/ManualPricingSourceTest.php`.

- [ ] **Step 1: Migration** — add nullable `unit` (string, default `'per_token'`), `effective_from` (date, nullable), `note` (string, nullable) to the overrides table.

- [ ] **Step 2: Failing test** — regolo EUR per-1M entry resolves to per-token:

```php
public function test_per_million_eur_override_normalizes_to_per_token(): void
{
    \Padosoft\LaravelAiFinOps\Models\PricingOverride::create([
        'model' => 'Llama-3.3-70B-Instruct', 'provider' => 'regolo',
        'input_cost_per_token' => 0.60, 'output_cost_per_token' => 2.70, // entered per-1M EUR
        'unit' => 'per_million', 'currency' => 'EUR',
    ]);
    $price = \Padosoft\LaravelAiFinOps\Models\PricingOverride::query()
        ->where('model', 'Llama-3.3-70B-Instruct')->first()->toModelPrice();
    $this->assertEqualsWithDelta(0.60 / 1_000_000, $price->inputPerToken, 1e-12);
    $this->assertEqualsWithDelta(2.70 / 1_000_000, $price->outputPerToken, 1e-12);
    $this->assertSame('EUR', $price->currency);
    $this->assertSame('override', $price->source);
}
```

- [ ] **Step 3: Run, expect FAIL.**

- [ ] **Step 4: Update `toModelPrice()`** — divide by 1_000_000 when `unit === 'per_million'` (input/output and cache costs). Add `'unit'` to `$casts`.

- [ ] **Step 5: Implement `ManualPricingSource`** (`name()='manual'`) — `all()` returns overrides keyed by model as a LiteLLM-style attr map; `sync()` returns the row count; `syncedAt()` returns the max `updated_at`. (It reads the DB, so under tests it is naturally hermetic.)

- [ ] **Step 6: Run green** + Pint. **Step 7: Commit** `feat(pricing): manual source + per-1M/EUR overrides (regolo)`. Closure loop.

---

## Task 4 (M8.3): `PricingSourceManager`

**Files:** Create `src/Pricing/PricingSourceManager.php`; Modify `src/LaravelAiFinOpsServiceProvider.php`; Test `tests/Feature/PricingSourceManagerTest.php`.

- [ ] **Step 1: Failing test** — manager returns only enabled sources in configured order and merges catalogs with a `_source` tag.

```php
public function test_sources_respect_enabled_and_order(): void
{
    config()->set('ai-finops.pricing.sources', ['manual', 'litellm']);
    config()->set('ai-finops.pricing.openrouter.enabled', false);
    $mgr = $this->app->make(\Padosoft\LaravelAiFinOps\Pricing\PricingSourceManager::class);
    $names = array_map(fn ($s) => $s->name(), $mgr->sources());
    $this->assertSame(['manual', 'litellm'], $names);
}
```

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Implement** — constructor receives the three sources (or a `[name => source]` map); `sources()` filters by `config('ai-finops.pricing.sources')` order + each source's enabled flag; `merged()` unions `all()` adding `'_source' => $name` (first listed wins on key collision so order matters); `syncAll()` returns `[name => count]`.

- [ ] **Step 4: Wire the container** — in `register()`:

```php
$this->app->singleton(\Padosoft\LaravelAiFinOps\Pricing\PricingSourceManager::class, function ($app) {
    return new \Padosoft\LaravelAiFinOps\Pricing\PricingSourceManager([
        'litellm' => $app->make(\Padosoft\LaravelAiFinOps\Pricing\LiteLLMPricingSource::class),
        'openrouter' => $app->make(\Padosoft\LaravelAiFinOps\Pricing\OpenRouterPricingSource::class),
        'manual' => $app->make(\Padosoft\LaravelAiFinOps\Pricing\ManualPricingSource::class),
    ], $app['config']);
});
```

(Keep `PricingSource::class` → LiteLLM for back-compat.)

- [ ] **Step 5: Run green** + Pint. **Step 6: Commit** `feat(pricing): PricingSourceManager`. Closure loop.

---

## Task 5 (M8.4): Multi-source resolution in `PricingRegistry`

**Files:** Modify `src/Pricing/PricingRegistry.php`, `src/Pricing/ModelPrice.php`; Test `tests/Feature/MultiSourceResolutionTest.php`.

- [ ] **Step 1: Failing tests** covering the matrix:

```php
public function test_provider_source_map_routes_to_named_source(): void
{
    // openrouter provider → openrouter source even if litellm also has the model
    config()->set('ai-finops.pricing.provider_source_map', ['openrouter' => 'openrouter']);
    // ...seed both sources with the same model id, different prices...
    $price = $this->registry()->priceFor('meta-llama/llama-3.3-70b-instruct', 'openrouter');
    $this->assertSame('openrouter', $price->source);
}

public function test_freshest_synced_at_wins_when_unmapped(): void
{ /* two sources have the model; the one with the later syncedAt() wins */ }

public function test_env_default_winner_breaks_ties(): void
{ /* equal/unknown syncedAt → first in pricing.default_winner wins */ }

public function test_manual_override_still_wins_when_overrides_win_true(): void
{ /* DB override beats any feed */ }
```

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Implement** — swap the `PricingSource $source` dependency for `PricingSourceManager $manager`. New private `resolveFromSources(string $model, ?string $provider): ?ModelPrice`:
  1. If `provider` is in `provider_source_map`, fetch from that named source only.
  2. Else gather candidate sources whose `all()` contains `$model`; pick max `syncedAt()`; tie/null → first match in `default_winner` order.
  3. Build via `ModelPrice::fromLiteLLM($model, $attr, $sourceName)`, attaching `syncedAt`.
  Keep the override-wins precedence exactly as today (override → resolved base → fallback).

- [ ] **Step 4: Extend `ModelPrice`** — add optional `?DateTimeInterface $syncedAt = null`, `?string $upstreamProvider = null` (readonly, defaulted, back-compat).

- [ ] **Step 5: Run green** + Pint. **Step 6: Commit** `feat(pricing): multi-source resolution (map→freshest→tiebreak)`. Closure loop.

---

## Task 6 (M8.6): Subscription coverage windows (€0)

**Files:** Create `src/Models/SubscriptionWindow.php`, migration; Modify `src/Enums/CallStatus.php`, `src/Metering/MeteringListener.php`, `src/LaravelAiFinOpsServiceProvider.php`; Test `tests/Feature/SubscriptionCoverageTest.php`.

- [ ] **Step 1: Migration** `…create_ai_finops_subscription_windows_table` — columns: `id`, `provider` (string 64, indexed), `label` (string), `starts_at` (datetime, nullable), `ends_at` (datetime, nullable), `enabled` (bool default true), `tenant_id` (string 64 nullable), `model` (string 128 nullable), `note` (text nullable), timestamps. Use `''` sentinels where columns join a unique index (RULES gotcha) — here no unique needed, plain index `(provider, enabled)`.

- [ ] **Step 2: `CallStatus::Covered`** enum case (value `'covered'`).

- [ ] **Step 3: Failing test** — active window forces €0 + `covered` + tag:

```php
public function test_active_subscription_window_zeroes_cost(): void
{
    \Padosoft\LaravelAiFinOps\Models\SubscriptionWindow::create([
        'provider' => 'anthropic', 'label' => 'claude-max',
        'starts_at' => now()->subDay(), 'ends_at' => null, 'enabled' => true,
    ]);
    // dispatch a metered anthropic call (use the existing metering test harness)...
    $row = \Padosoft\LaravelAiFinOps\Models\UsageRecord::query()->latest('id')->first();
    $this->assertSame(0.0, (float) $row->cost_total);
    $this->assertSame('covered', $row->status);
    $this->assertSame('claude-max', $row->metadata['covered_by'] ?? null);
    $this->assertGreaterThan(0, $row->tokens_input); // tokens still recorded
}

public function test_expired_window_prices_normally(): void
{ /* ends_at in the past → normal cost > 0, status 'recorded' */ }
```

- [ ] **Step 4: Run, expect FAIL.**

- [ ] **Step 5: Implement matching** — `SubscriptionWindow::activeFor(string $provider, ?string $tenant, ?string $model, CarbonInterface $at): ?self` scope: `enabled`, `provider` match, `starts_at` null-or-≤at, `ends_at` null-or-≥at, `tenant_id` null-or-matches, `model` null-or-matches. In `MeteringListener::baseEnvelope()` after computing `$cost`: if a window matches, set cost to zero, status `Covered`, and add `covered_by`/frozen rates to metadata.

- [ ] **Step 6: Observe the model** for audit in the service provider's `bootAuditObservers()` list.

- [ ] **Step 7: Run green** + Pint. **Step 8: Commit** `feat(pricing): flat-rate subscription coverage windows (€0)`. Closure loop.

---

## Task 7 (M8.8): Freeze richer provenance on the ledger

**Files:** Modify `src/Metering/MeteringListener.php`; Test `tests/Feature/LedgerProvenanceTest.php`.

- [ ] **Step 1: Failing test** — metadata carries `price_source`, `rate_input`, `rate_output`, `source_synced_at`, and `upstream_provider` when present on the price.

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Implement** — extend the `metadata` array built in `baseEnvelope()` to include the resolved price's per-token rates, `syncedAt`, and `upstreamProvider`. (No schema change; goes into the existing `metadata` JSON. Never re-price history.)

- [ ] **Step 4: Run green** + Pint. **Step 5: Commit** `feat(metering): freeze rate/source/upstream provenance on ledger`. Closure loop.

---

## Task 8 (M8.7): Overhead overlay for estimates

**Files:** Modify `src/Pricing/CostCalculator.php`; Modify estimate callers (`WhatIfController`, forecast, preflight estimate); Test `tests/Feature/OverheadOverlayTest.php`.

- [ ] **Step 1: Failing test** — `CostCalculator::withOverhead($cost, $provider)` adds `fees.<provider>.markup_pct`%; metered ledger path is unaffected.

```php
public function test_overhead_applies_to_estimate_not_ledger(): void
{
    config()->set('ai-finops.pricing.fees', ['openrouter' => ['markup_pct' => 5.5]]);
    $calc = $this->app->make(\Padosoft\LaravelAiFinOps\Pricing\CostCalculator::class);
    $est = $calc->withOverhead(100.0, 'openrouter');
    $this->assertEqualsWithDelta(105.5, $est, 1e-9);
    $this->assertEqualsWithDelta(100.0, $calc->withOverhead(100.0, 'openai'), 1e-9); // no fee
}
```

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Implement** `withOverhead()`; call it ONLY in forward/estimate paths (what-if, forecast, preflight). Leave `MeteringListener` raw.

- [ ] **Step 4: Run green** + Pint. **Step 5: Commit** `feat(pricing): per-provider overhead % overlay for estimates`. Closure loop.

---

## Task 9 (M8 — routing synergy): cost-aware routing treats covered providers as free

**Files:** Modify `src/Routing/RoutingEngine.php`; Test extend the routing test.

- [ ] **Step 1: Failing test** — given an active subscription window for provider X, the engine ranks X as effectively-free (cost 0) when comparing candidates.

- [ ] **Step 2–4: Implement + green + commit** `feat(routing): prefer subscription-covered providers`. Closure loop.

---

## Task 10 (M8.9): APIs — per-source status, models filter, subscription CRUD

**Files:** Modify `src/Http/Controllers/PricingController.php`, `routes/api.php`; create `src/Http/Controllers/SubscriptionWindowController.php`; Tests `tests/Feature/PricingApiTest.php` (extend), `tests/Feature/SubscriptionWindowApiTest.php`.

- [ ] **Step 1: Failing tests** —
  - `GET pricing/sync/status` → `{ sources: [{name, enabled, synced_at, models, has_key}] }`; `has_key` true only when an OpenRouter key is configured, and the **key itself never appears** in the payload.
  - `GET pricing/models?source=openrouter` → rows include a `source` field and only that source's rows.
  - `POST pricing/overrides` with `unit=per_million`, `currency=EUR` persists and round-trips.
  - `subscription-windows` CRUD (index/store/update/destroy) validates and round-trips; secrets-free.

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Implement** — `syncStatus()` iterates `PricingSourceManager::sources()` (+ disabled ones for completeness) emitting `has_openrouter_key` via `filled(config('ai-finops.pricing.openrouter.key'))`; `models()` uses `merged()` and honors `?source=`; extend `validateOverride()` with `unit` (`in:per_token,per_million`), `effective_from` (`nullable|date`), `note` (`nullable|string`). New `SubscriptionWindowController` mirrors `PricingController` override-CRUD style with validation. Register routes under the existing privileged group (`auth_middleware`).

- [ ] **Step 4: Run green** + Pint. **Step 5: Commit** `feat(api): per-source pricing status + subscription-windows CRUD + override units`. Closure loop.

---

## Task 11 (M8.10): Docs — README + config + admin handoff

**Files:** Modify `README.md`, `config/ai-finops.php` (comments); create `../laravel-ai-finops-admin/docs/superpowers/specs/2026-05-31-multi-source-pricing-admin-design.md` (seed from spec §10).

- [ ] **Step 1:** Rewrite the README "always-fresh pricing" section: LiteLLM base ⊕ **OpenRouter live** ⊕ **manual (regolo)**; per-provider authority map; freshest-sync tiebreak; subscription coverage (€0); overhead %; "real cost = who billed you"; manual masks for costs + canoni.
- [ ] **Step 2:** Ensure every new config key has an explanatory comment.
- [ ] **Step 3:** Write the admin handoff spec seeded from §10 (settings, pricing-registry source badge + manual mask, price-watcher source dimension, call-trace provenance, the API contract delta).
- [ ] **Step 4: Commit** `docs: multi-source pricing README + config + admin handoff`. Closure loop (docs-only: PHPUnit/Pint still run; no Playwright).

---

## Task 12 (M8.11): Admin alignment (separate repo)

- [ ] In `../laravel-ai-finops-admin`: run brainstorming → writing-plans → implementation from the handoff spec. **Playwright E2E for EVERY new UI interaction** (settings source toggles, authority-map editor, OpenRouter key write-only field, manual price mask with unit/currency, subscription-window CRUD, pricing-registry source filter/badge, call-trace provenance). Each step follows the same closure loop. (Tracked as its own plan in that repo.)

---

## Task 13 (M8.12 closeout): Capture know-how into rules/skills/AGENTS

- [ ] **Step 1:** Review `docs/LESSON.md` and all M8 learnings (OpenRouter normalization quirks, regolo manual-only pricing, multi-source resolution order, subscription €0 semantics, has_*-key handling, currency/FX mixing).
- [ ] **Step 2:** Add a "Multi-source pricing" subsection to `docs/RULES.md` Conventions & Gotchas; update `AGENTS.md`; extend `.claude/skills/laravel-ai-finops-plan/SKILL.md` with the new resume points.
- [ ] **Step 3: Commit** `docs: capture M8 know-how into RULES/AGENTS/skill`. Closure loop.

---

## Task 14 (M8.13 release): macro PR + tag + GitHub release

- [ ] **Step 1:** Open the macro PR `feat/core-multisource-pricing` → `main`; @copilot reviewer; CI all-green + Copilot resolved.
- [ ] **Step 2:** Merge.
- [ ] **Step 3:** **Confirm version + timing with the user** (public/irreversible — per PROGRESS M7.3). Then bump version, `git tag vX.X.X`, push the tag, and cut the GitHub release with notes summarizing multi-source pricing, subscriptions, manual masks.

---

## Self-Review (done)

- **Spec coverage:** §A→T1/T2/T4; §B→T5; §C subscriptions→T6, overhead→T8; §D→T7; §E→T10; §G manual masks→T3/T10; §10 admin→T11/T12; §11 milestones map 1:1; closeout/release→T13/T14. No gaps.
- **Placeholders:** Tests with `/* ... */` are the well-specified matrix cases from the spec resolution matrix (T5/T6/T9) — the engineer seeds two sources with the same model id at different prices and asserts the documented winner; this is fully determined by the spec, not a TODO.
- **Type consistency:** `syncedAt(): ?DateTimeInterface`, `name()` strings (`litellm`/`openrouter`/`manual`/`override`), `CallStatus::Covered`, `PricingSourceManager::{sources,merged,syncAll}`, `CostCalculator::withOverhead`, `ModelPrice::{syncedAt,upstreamProvider}` used consistently across tasks.
