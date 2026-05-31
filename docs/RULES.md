# Project Rules — laravel-ai-finops

## Source Of Truth

- Canonical plan: **`docs/PLAN.md`** (repo-relative; author's local copy lives under `~/.claude/plans/`
  on Unix or `%USERPROFILE%\.claude\plans\` on Windows).
- Governance reference (copied/adapted from): `../product_image_discovery_admin` (AGENTS.md, docs/*).
- README "WOW" model: `https://github.com/lopadova/AskMyDocs/blob/main/README.md`.
- Backbone SDK contract: the official `laravel/ai` package (`^0.6.8 || ^0.7`).

## Implementation Defaults

- Laravel 13.x, PHP `^8.3` (allow `^8.4` if a dependency requires it).
- SQLite default DB for local/dev/tests; queue default `sync`.
- Single metering hook: middleware/listener on the `laravel/ai` request/response lifecycle.
- Core value object `AiCallEnvelope` (provider, model, tokens in/out/cached, cost, currency, tenant,
  user, cost-center, agent-step, purpose-tag, `trace-id`) — also the cross-package agentic glue.
- Package API under `/api/ai-finops/...`; admin JSON endpoints use session/CSRF.
- Everything toggleable via `config/ai-finops.php`; multi-tenant aware.

## Security Rules

- Never return provider API keys, channel secrets/webhooks, tokens, or partial previews in JSON/UI.
- Secret-bearing resources expose only `has_*` booleans; write-only forms with explicit Replace/Clear.
- Sanitized errors only: no stack traces or raw provider payloads in operator-facing JSON.
- PII never logged in the ledger; integrate `pii-redactor` for guardrail-linked spend.

## Testing Rules (gates)

Every completed slice runs the relevant subset of:

```text
composer validate --strict
vendor/bin/phpunit            # or `npm run phpunit` if a Herd/PATH wrapper is added
npm run build
npm run test                  # Vitest (admin)
npm run e2e                   # Playwright (admin / UI slices)
```

- Confirm the local PHP version satisfies Laravel 13 / PHP 8.3+ before marking a PHP gate blocked.
- If a tool is unavailable, blocked by sandbox/network, or needs remote CI, record the exact blocker in
  `docs/PROGRESS.md`.

## Review Rules

- Local pre-push gate: `copilot --autopilot --yolo -p "/review <full branch diff vs origin/main>"`
  (whole branch diff; temp file if too large). Push only at zero comments + green local tests.
- PR review: GitHub Copilot Code Review (`gh pr edit <PR> --add-reviewer @copilot`; GraphQL fallback on
  `read:project`). Do not substitute with `@codex review` unless explicitly asked.

## Documentation Rules

- Update `docs/PROGRESS.md` after meaningful work (dated `YYYY-MM-DD`, with resume point).
- Update `docs/LESSON.md` on any non-obvious setup fact, API contract detail, test workaround, or
  lesson learned from Copilot comments. Pass it to every subagent and re-read at session start.
- Keep entries dated `YYYY-MM-DD`.

## Conventions & Gotchas (consolidated from LESSON.md)

These recurred across M0–M7; treat them as standing rules:

- **Test helpers must not collide with Testbench/PHPUnit methods.** `seed()` and `status()` are
  reserved (Orchestra `TestCase` / PHPUnit). Name fixtures `seedRow()`, `makeStatus()`, etc.
- **Package routes default to `['api']`, not `['web']`.** The `web` group isn't registered in the
  Testbench package context and errors at middleware resolution. Privileged endpoints get
  `auth_middleware`; the host sets `['web']` where session/CSRF exist.
- **JSON serializes whole floats as ints.** `assertJsonPath('x', 50.0)` fails when the value comes
  back as `50`. Assert the int, or use `assertEquals` for tolerant numeric comparisons.
- **Keep tests hermetic.** Bind an in‑memory `PricingSource` (and fake provider seams) in `TestCase`
  so the suite never hits the LiteLLM network mirror.
- **Eloquent `array` cast owns JSON encoding.** Don't `json_encode()` a value you store into an
  `array`‑cast column (double‑encoding); pass the raw array.
- **`Rule::exists()` must be connection‑qualified** (`"connection.table"`) when the package may use a
  non‑default `storage.connection`.
- **Composite uniques need non‑NULL sentinels.** NULLs aren't equal on MySQL/Postgres, so store `''`
  (not NULL) for "global/unscoped" rows that participate in a unique index.
- **Mutable per‑request services are `scoped`, not `singleton`** (e.g. `TraceContext`) — singletons
  leak state across requests/jobs under Octane/Swoole/queue workers.
- **Never fabricate favourable numbers.** Unpriced/unknown inputs return `null` + a message, not a
  zero that implies "100% savings".
- **Secrets never serialize.** Channel/provider configs expose only `has_*` booleans; audit redacts
  sensitive keys.
- **Sibling‑package integrations are Option‑1 seams** (contract + toggle + Null default), never hard
  composer deps.

### Multi‑source pricing (M8)

- **Add a feed = implement `PricingSource`** (`all/sync/name/syncedAt`) and register it in the
  `PricingSourceManager` map in the service provider + add its name to `config pricing.sources`.
  `syncedAt()` is the ONLY freshness signal (feeds don't date prices) — stamp it on successful sync.
- **Resolution order is fixed:** manual DB override (wins when `overrides_win`) → `provider_source_map`
  (who bills you) → freshest `syncedAt()` → `default_winner` tie‑break. The `manual` source resolves
  through the currency‑aware override lookup (EUR‑safe), never `ModelPrice::fromLiteLLM` (USD‑hardcoded).
- **The raw ledger is pass‑through truth.** Subscription coverage zeroes cost in `MeteringListener`
  (`CallStatus::Covered` + `covered_by`, tokens kept, would‑be rate in `metadata.rate_*`); the
  overhead % (`CostCalculator::withOverhead`) is for estimates ONLY. Never mutate metered rows for fees.
- **Feed‑less providers (e.g. regolo.ai) = manual source**, entered per‑1M / EUR via `pricing/overrides`
  (`unit`, `currency`). Subscriptions/canoni are `pricing/subscription-windows` CRUD.
- **OpenRouter key is a secret:** expose `has_openrouter_key` only; keyless public list works via
  `allow_keyless`.
- **Tests:** wrap fakes in `PricingSourceManager`; `RefreshDatabase` for any DB write; keep hermetic.
