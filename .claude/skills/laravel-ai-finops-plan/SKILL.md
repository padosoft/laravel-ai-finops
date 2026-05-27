---
name: laravel-ai-finops-plan
description: Use when working on the laravel-ai-finops package (or its admin) to follow the mandatory branch/PR/Definition-of-Done/Copilot-loop workflow and resume from the correct point.
---

# laravel-ai-finops — execution procedure

Before editing anything:
1. Read `docs/PROGRESS.md` (resume point), `docs/LESSON.md` (discoveries), `docs/RULES.md`, `AGENTS.md`.
2. Read the canonical plan: `%USERPROFILE%\.claude\plans\allora-leggi-questi-repo-polished-hearth.md`.
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
glue; pricing = LiteLLM base ⊕ Padosoft override (override wins); admin = React+Vite+Tailwind; secrets
never exposed. `laravel-ai-chat` is demo-only; `agentic-qa-kit` is an external Bun/TS QA runner.

At package completion: WOW README, consolidate LESSON.md into rules/skills/AGENTS.md, tag `vX.X.X` + release.
