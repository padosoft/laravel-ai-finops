---
name: laravel-ai-finops-plan
description: Use when working on the laravel-ai-finops package (or its admin) to follow the mandatory branch/PR/Definition-of-Done/Copilot-loop workflow and resume from the correct point.
---

# laravel-ai-finops — execution procedure

Before editing anything:
1. Read `docs/PROGRESS.md` (resume point), `docs/LESSON.md` (discoveries), `docs/RULES.md`, `AGENTS.md`.
2. Read the canonical plan: `docs/PLAN.md` (repo-relative; local copy under `~/.claude/plans/`).
3. Identify the current macro-task (M0–M7) and the smallest open subtask.

For each subtask:
1. Scope to one macro/subtask; preserve the `/api/ai-finops/...` contract and `config/ai-finops.php` toggles.
2. Implement, then satisfy the **Definition of Done**: PHPUnit + Vitest; Playwright for ALL UI
   interactions if UI/UX is touched (code-only ⇒ no Playwright).
3. Run local gates GREEN (`composer validate --strict`, phpunit, `npm run build`, vitest, playwright).
4. Local Copilot review: `copilot --autopilot --yolo -p "/review <full branch diff vs origin/main>"`
   (whole branch diff; temp file if too large). Push only at zero comments.
5. Open PR into the macro branch; reviewer = GitHub Copilot (GraphQL fallback on `read:project`).
6. Poll CI + Copilot until green/resolved; merge; then proceed. Never fake the loop.
7. Update `docs/PROGRESS.md` (resume point) and `docs/LESSON.md` (any discovery, incl. Copilot lessons).

Key facts: backbone `laravel/ai`; metering = single middleware/listener; `AiCallEnvelope` + `trace-id`
glue; pricing = **multi-source** (LiteLLM ⊕ OpenRouter live ⊕ manual/regolo) behind `PricingSourceManager`,
resolved override → `provider_source_map` → freshest `syncedAt()` → `default_winner`; flat-rate
**subscription windows** zero covered calls; `markup_pct` overhead is estimate-only; raw ledger is
pass-through truth. admin = React+Vite+Tailwind; secrets never exposed (`has_openrouter_key`).
`laravel-ai-chat` is demo-only; `agentic-qa-kit` is an external Bun/TS QA runner.
Tests: wrap fakes in `PricingSourceManager`; `RefreshDatabase` for DB writes; run `php vendor/bin/phpunit`
from PowerShell (the bash shim can't find php).

Every analysis/plan MUST end with a **dedicated standalone final task: full README audit & update**
(its own task, last before tag/release) — keep `README.md` precise/meticulous, every feature reflected
in EVERY relevant section (hero, How-it-works, Features table, Configuration w/ worked example, API
overview, commands); no stale phrasing. Same rule for the admin README. See `docs/RULES.md`.

At package completion: WOW README, consolidate LESSON.md into rules/skills/AGENTS.md, tag `vX.X.X` + release.
