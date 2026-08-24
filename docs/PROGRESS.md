# PROGRESS — laravel-ai-finops

Dated work log (YYYY-MM-DD). Newest first. Records what was done and the resume point so any session can
continue cleanly after an interruption.

## 2026-06-01

### M9 — Cost resolution cascade — ✅ MERGED (PR #14) + RELEASED v1.2.0 (2026-06-01)
- Design+plan: `docs/superpowers/specs/2026-06-01-cost-resolution-cascade-design.md`,
  `docs/superpowers/plans/2026-06-01-cost-resolution-cascade.md`.
- **Done (179 PHPUnit green):** config (actual_cost, token_estimation) + tiktoken suggest; ledger
  columns `cost_method`/`tokens_estimated`/`billed_cost`/`billed_currency` + `CostMethod` enum;
  `TokenEstimator` (Heuristic + optional Tiktoken auto-bind); `RawResponseCapture` + opt-in `Http`
  capture middleware (recovers OpenRouter `usage.cost` that laravel/ai drops); `ActualCostResolver`
  seam + manager + OpenRouter resolver; `CostResolutionService` cascade wired into `MeteringListener`;
  fal unit-cost resolver (`unit_rate`); API estimate-from-prompt + usage cost_method/billed_cost +
  settings estimator/actual-cost; README full audit (cascade + WOW "overcame laravel/ai cost drop").
- **Provider matrix proven** (`CostResolutionCascadeTest`): OpenRouter→actual, OpenAI/Anthropic/Gemini→
  computed, regolo→computed (manual EUR/1M), unknown→estimated; fal→unit (`FalUnitCostTest`).
- **Closed:** 2 local Copilot rounds + PR #14 (@copilot: 4 issues → fixed: generation-lookup root,
  currency-mix guard, fal exact-provider preference, draft metadata) → CI green (8.3/8.4/8.5) → merged →
  **released v1.2.0**.
- **NEXT:** admin alignment to M9 (method badge, estimated marker, billed-vs-computed, estimate-from-prompt
  screen, fal unit rates) — full loop → admin v1.2.0.

## 2026-05-31

### M8 — Multi-source pricing — ✅ MERGED (PR #13) + RELEASED v1.1.0 (2026-06-01)
- Design + plan: `docs/superpowers/specs/2026-05-31-multi-source-pricing-design.md`,
  `docs/superpowers/plans/2026-05-31-multi-source-pricing.md`. Research: LiteLLM vs OpenRouter vs regolo.
- **Done (code + tests green, 153 PHPUnit):**
  - M8.0 config scaffold (sources, default_winner, openrouter{enabled,url,key,allow_keyless,use_endpoints},
    provider_source_map, fees).
  - M8.1 `PricingSource::syncedAt()` + LiteLLM stamping (success-only).
  - M8.2 `OpenRouterPricingSource` (live API → LiteLLM attr map; keyless/keyed; graceful).
  - M8.3 manual source: `PricingOverride` `unit`/`effective_from`/`note` (per-1M/EUR) + `ManualPricingSource`.
  - M8.4 `PricingSourceManager` (enabled+ordered, merged, syncAll) + container wiring.
  - M8.5 `PricingRegistry` multi-source resolution (override → map → freshest → tiebreak); `ModelPrice`
    provenance (`syncedAt`/`upstreamProvider`). TestCase wraps fake in manager (hermetic).
  - M8.6 `SubscriptionWindow` + migration + €0 coverage in `MeteringListener` (`CallStatus::Covered`) + audit.
  - M8.7 frozen ledger provenance (price_source, rate_input/output, source_synced_at, upstream_provider).
  - M8.8 overhead overlay `CostCalculator::withOverhead` (estimates; wired into what-if).
  - M8.9 routing prefers covered providers (zero cost metric).
  - M8.10 API: per-source sync status + `has_openrouter_key`, models `source` field/`?source=` filter,
    override units, `pricing/subscription-windows` CRUD.
  - M8.11 docs: README multi-source/subscriptions; config comments; admin handoff spec in admin repo.
  - M8.12 closeout: LESSON/RULES updated with M8 know-how.
- **Closed:** local Copilot `/review` (no issues) → PR #13 → @copilot (43/43 files, 0 comments) →
  CI green (8.3/8.4/8.5) → squash-merged → **tag + release v1.1.0** (user-confirmed minor bump).
- **Resume point / NEXT:** admin UI implementation in `../laravel-ai-finops-admin` from the handoff spec
  `docs/superpowers/specs/2026-05-31-multi-source-pricing-admin-design.md` (brainstorm → plan → impl,
  Playwright E2E per interaction). Phase-2 backend: OpenRouter per-endpoint price ingestion (`use_endpoints`).

## 2026-05-27

### M7 — Finalization (branch `chore/release`) — IN PROGRESS
- **M7.1** WOW README (core) written. No banner/screenshots (no `resources/`; admin owns those).
- **M7.2** Consolidated LESSON.md gotchas into `docs/RULES.md` "Conventions & Gotchas".
- **M7.3** Tag `vX.X.X` + GitHub release — PENDING user confirm (version + timing; public/irreversible).
- Status: M0–M4 + M4.5 + M6 merged (PRs #1–#8). **Core feature-complete: 117 tests, CI 8.3/8.4/8.5.**
  M5 (admin React UI) = Lorenzo's parallel track.

### M4 — WOW features (branch `feat/core-wow`) — MERGED (PRs #6 + #7); 8/9 then M4.5 in PR #7
- M4.1 Forecaster (run-rate) + AnomalyDetector + ack. M4.2 cost-aware routing (QualityScoreProvider seam →
  eval-harness; RoutingEngine; rules + simulate). M4.3 what-if (replay re-priced; scenarios).
  M4.4 StreamMeter (live cost + mid-stream cutoff). M4.6 credit pools (CRUD/topup/ledger).
  M4.7 CO₂/ESG footprint. M4.8 guardrail-linked spend (GuardrailProvider seam → pii-redactor/ai-act).
  M4.9 FinOps copilot (CopilotProvider seam → ai-chat/AskMyDocs; query+history).
- All external integrations = Option-1 seams (contracts + toggles, no hard dep), tested with fakes.
- **M4.5 price-change watcher = REMAINING** (small follow-up PR). Gates GREEN: PHPUnit **111/111**, Pint ok.

### M3.4 — Multi-channel alerts (branch `feat/core-alerts`) — MERGED (PR #5)
- AlertChannel (config never serialized → has_config), AlertRule (budget threshold, last_notified_pct
  de-dupe + re-arm), AlertLogEntry; AlertDispatcher (crossing fires once + logs + BudgetThresholdReached
  event for host delivery); `ai-finops:check-alerts`; AlertController (channels/rules/log/test).
- Local Copilot CLI auth dropped mid-session (`copilot /login` to restore) — relying on PR review.
- Gates GREEN: PHPUnit **91/91**, Pint passed.

### M3 (1/2) — Enterprise governance (branch `feat/core-enterprise`) — MERGED (PR #4)
- **M3.1** Chargeback/showback: CostCenter model + cost-centers CRUD + allocation report (unallocated bucket).
- **M3.2** Declarative policies (Policy DSL: scope+min_cost+model match → action), PolicyController CRUD/validate/simulate;
  approval workflow (SpendApproval + ApprovalController); PolicyEngine consults policies (Block & RequireApproval halt,
  Downgrade/Throttle/Queue advisory).
- **M3.3** Audit trail (AuditObserver on all governance models → audit_log + GET /audit, config-toggle, secret redaction);
  FxConverter (base→display, callable provider, 1:1 fallback).
- Shipping M3.1–M3.3 as PR "M3 (1/2)". **M3.4 alerts (multi-channel + thresholds) = remaining**, next branch.
- Gates GREEN locally: PHPUnit **84/84**, Pint passed, hermetic.

### M2 — Budgets & enforcement (branch `feat/core-budgets`) — COMPLETE (pending macro PR→main)
- **M2.1** Budget hierarchy (scopes + periods), `BudgetResolver`, `BudgetStatus`, Budgets API (CRUD/tree/burndown).
- **M2.2** `PolicyEngine` (config + scoped kill-switch + hard-budget-exceeded block); `EnforcementListener`
  on laravel/ai PRE-events (`PromptingAgent`/`GeneratingEmbeddings`) throwing `BudgetExceededException`
  (HTTP 402); `KillSwitch` model/migration; Settings API (read-only snapshot), kill-switch GET/POST,
  diagnostics/estimate. Shared `TenantResolver` (MeteringListener refactored to use it).
- **M2.3** Artisan `ai-finops:report` + `ai-finops:prune`. NOTE: the inspiration packages' `reset-budgets`
  is unnecessary here — budget periods are computed from the ledger, so there is nothing to reset;
  `prune` (retention) replaces it.
- Reordering note: advanced Policy DSL + Policies CRUD moved to M3 (with throttle/downgrade/approval).
- Gates GREEN locally: PHPUnit **66/66**, Pint passed, hermetic. Next: macro PR `feat/core-budgets`→main.

### M1 — Metering foundation (branch `feat/core-metering`) — COMPLETE (pending macro PR→main)
- **M1.1** Assessment: backbone `laravel/ai` confirmed; `agentic-qa-kit` is Bun/TS (external QA runner). Recorded in LESSON.
- **M1.2** `AiCallEnvelope` + `TokenUsage`/`CostBreakdown` + enums; immutable `ai_finops_usage_ledger` + `UsageRecord`.
- **M1.3** Single metering hook (`MeteringListener`) on `laravel/ai` events (AgentPrompted/AgentStreamed/EmbeddingsGenerated); `UsageRecorder`/`DatabaseUsageRecorder`; tenant resolver; class_exists-guarded registration.
- **M1.4** `PricingRegistry` (LiteLLM base ⊕ Padosoft override wins) + `LiteLLMPricingSource` + `CostCalculator`; wired into the hook (real cost + price_source).
- **M1.5** API: Usage (`index`/`show`/`trace`) + Pricing (`models`/`sync`/`sync/status`/overrides CRUD). Privileged routes gated by `auth_middleware`; public `health`.
- **M1.6** API: Dashboard (`kpis`/`spend-trend`/`top-models`/`top-tenants`).
- **M1-review** Copilot `/review` applied 4 fixes: (1) `kpis()` currency label was `display` (no FX in M1) → changed to `base`; (2) `sync()` updated `synced_at` even on failure → only on success; (3) `spendTrend()`/`index()` `$request->date()` could 500 on invalid input → validate first; (4) `storeOverride()` always returned 201 → uses `wasRecentlyCreated`.
- Gates GREEN locally: PHPUnit **40/40**, Pint passed, hermetic (no network). Next: macro PR `feat/core-metering`→main with Copilot loop.


### M0 — Governance bootstrap & scaffolding (branch `chore/governance-bootstrap`) — IN PROGRESS
- Created branch `chore/governance-bootstrap` off `main`.
- **M0.1** Added governance docs adapted from `../product_image_discovery_admin`:
  `AGENTS.md`, `docs/RULES.md`, `docs/PROGRESS.md`, `docs/LESSON.md`.
- **M0.2** Added Claude-format governance: `CLAUDE.md`, `.claude/skills/laravel-ai-finops-plan/SKILL.md`.
- **M0.4** Added `docs/ADMIN-TEMPLATE.md` (React+Vite+Tailwind template guide for the admin repo).
- **M0.3** Core scaffolding DONE: `composer.json` (laravel/ai in require-dev for broad compat, illuminate
  ^12||^13, PHP ^8.3), `LaravelAiFinOpsServiceProvider`, `config/ai-finops.php` (all toggles), `routes/api.php`
  (health), `phpunit.xml` (Unit/Feature/E2E), `pint.json`, `.github/workflows/ci.yml` (PHP 8.3/8.4 blocking,
  8.5 experimental), `tests/` (7 tests). Local gates GREEN: `composer validate --strict` ok, PHPUnit 7/7
  on PHP 8.4.21, Pint passed.
- **M0.5** Admin repo scaffolding = DELEGATED to Lorenzo via `docs/ADMIN-TEMPLATE.md` (he builds the
  React+Vite+Tailwind template in parallel).
- **Next (resume point):** local Copilot review of the branch diff → push → open PR
  `chore/governance-bootstrap` → main → Copilot reviewer + CI → merge. Then start M1 (metering foundation).

> Plan: `docs/PLAN.md` (repo-relative; author's local copy under `~/.claude/plans/` or
> `%USERPROFILE%\.claude\plans\`). Macro tasks M0–M7 tracked in the session task list.

### Delegation budget meter (branch `task/delegation-budget-meter`, 2026-08-24) — v1.6.0
- IAM budget-bounded delegation (laravel-iam-agents v1.1): `src/Delegation/LedgerDelegationBudgetGuard`
  implements `Padosoft\Iam\Contracts\Delegation\DelegationBudgetGuard` (iam-contracts ^1.4, require-dev
  + suggest) summing the ledger by the new indexed `delegation_grant_id` column.
- `AiCallEnvelope` gained trailing `delegationGrantId` (positional-BC guarded by test); `TraceContext`
  gained the `delegation_grant_id` slot (ambient stamping via `MeteringListener`).
- Binding double-gated: `integrations.iam_delegation.enabled` (default false) AND `interface_exists`.
  Amount cap converted ledger-base→budget currency via `FxConverter`.
- Docs: `docs-site/docs/guides/delegation-budgets.md` (+nav, check+build green, 28 pages), README rows
  (Features + Integrations), `release.yml` added (workflow_dispatch tag+release, ecosystem pattern).
- Gates GREEN: PHPUnit 195/195 (8 new in `DelegationBudgetGuardTest`), Pint passed.

### Delegation read path (branch `task/delegation-read-path`, 2026-08-24) — v1.6.1
- Admin-parity follow-up (regola fissa #8): `delegation_grant_id` filter on GET usage index
  (one array element) + `GET dashboard/top-delegations` (topBy pivot: cost/calls/tokens per
  grant) + route; docs guide updated with the two read paths; 2 new tests. 197/197 green.
