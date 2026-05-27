# PROGRESS — laravel-ai-finops

Dated work log (YYYY-MM-DD). Newest first. Records what was done and the resume point so any session can
continue cleanly after an interruption.

## 2026-05-27

### M3.4 — Multi-channel alerts (branch `feat/core-alerts`) — COMPLETE (pending PR→main, completes M3)
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
