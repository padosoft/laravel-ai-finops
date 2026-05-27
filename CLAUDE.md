# CLAUDE.md — laravel-ai-finops

Project memory for Claude Code. **Read `AGENTS.md`, `docs/RULES.md`, `docs/PROGRESS.md`, and
`docs/LESSON.md` at the start of every session** — they are the authoritative operating rules.

## What this is
`padosoft/laravel-ai-finops`: enterprise AI spend-governance for Laravel (metering + budgets + policy
enforcement + FinOps), hooking the official `laravel/ai` SDK at a single point. Companion admin:
`../laravel-ai-finops-admin` (React + Vite + Tailwind).

## Non-negotiables (summary — full text in AGENTS.md / docs/RULES.md)
- Laravel 13.x, PHP `^8.3` (cover 8.4/8.5). Multi-tenant, config-toggle everywhere, EU-compliant.
- Backbone `laravel/ai` `^0.6.8 || ^0.7`; metering via one middleware/listener; `AiCallEnvelope` +
  `trace-id` is the cross-package glue.
- Pricing = LiteLLM mirror base ⊕ Padosoft local overrides (override wins).
- **Definition of Done**: objective + impl details + guardrail tests (PHPUnit, Vitest, and Playwright
  for ALL UI interactions when UI/UX is touched).
- **Branch & PR loop**: branch per macro-task → subtask PRs into it → macro PR into `main`; local
  `copilot --autopilot --yolo -p "/review <full branch diff>"` (zero comments before push) → PR with
  Copilot reviewer → CI green + Copilot resolved → merge. Never fake the loop.
- Keep `docs/PROGRESS.md` (resume point) and `docs/LESSON.md` (discoveries) current; pass LESSON.md to
  every subagent.
- Never expose secrets (`has_*` booleans only). Sanitized errors only.

## Skill
`.claude/skills/laravel-ai-finops-plan/SKILL.md` encodes this procedure for resumption.
