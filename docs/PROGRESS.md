# PROGRESS — laravel-ai-finops

Dated work log (YYYY-MM-DD). Newest first. Records what was done and the resume point so any session can
continue cleanly after an interruption.

## 2026-05-27

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
