# laravel-ai-finops — Agent Guide

`padosoft/laravel-ai-finops` is an **enterprise spend-governance package for AI** in Laravel: it meters
every AI call cross-provider, enforces budgets/policies, and provides FinOps (chargeback, forecast,
alerts, routing). It is the missing "governance" brick of the Padosoft agentic ecosystem.

The companion admin app lives in the sibling repo `../laravel-ai-finops-admin`.
The durable implementation plan is at:

```text
%USERPROFILE%\.claude\plans\allora-leggi-questi-repo-polished-hearth.md
```

If context is missing, read the plan first, then read:

- `docs/RULES.md`
- `docs/PROGRESS.md`  (where we are — resume point)
- `docs/LESSON.md`    (discoveries/fixes — always load before working and pass to every subagent)
- `.claude/skills/laravel-ai-finops-plan/SKILL.md`

## Operating Rules

- Laravel 13.x (latest), PHP `^8.3` (allow `^8.4` if a dependency requires it).
- The backbone is the official **`laravel/ai`** SDK (`^0.6.8 || ^0.7`). FinOps hooks a SINGLE point —
  a middleware/listener on the `laravel/ai` request/response lifecycle — so it meters every provider
  (incl. `padosoft/laravel-ai-regolo`, which is a `laravel/ai` provider) without touching other packages.
- `laravel-ai-chat` is only a DEMO of `laravel/ai` + Vercel AI SDK — NOT a capability to integrate.
- `agentic-qa-kit` is a Bun/TypeScript monorepo (not Laravel). Integrate it as an EXTERNAL QA runner
  driving the app over HTTP, correlated via the `trace-id` header — never via composer/laravel/ai.
- Everything is config-toggle driven (`config/ai-finops.php`) and multi-tenant. EU-compliant by default.
- Package API under `/api/ai-finops/...` (session/CSRF for the admin, not browser-held tokens).
- Never expose secrets (provider keys, channel webhooks): JSON exposes only `has_*` booleans; sanitized
  errors only (no stack traces / raw provider payloads).
- Update `docs/PROGRESS.md` after meaningful steps; update `docs/LESSON.md` on any non-obvious discovery.

## Definition of Done (every task/subtask)

A step is DONE only with: a precise objective, implementation details, and **guardrail tests**:

- PHPUnit for PHP logic.
- Vitest for JS/React units.
- **If it touches UI/UX → Playwright scenarios covering ALL interactions** (desktop + tablet).
- If it is code-only (no UI), Playwright is not required.

## Branch & PR Loop (mandatory)

One branch per macro-task (`M0..M7`). Each subtask → PR into the macro branch. Macro done → PR into `main`.

For each subtask:

1. Implement the smallest coherent subtask.
2. Run all relevant local gates GREEN: PHPUnit, Vitest, `npm run build`, Playwright.
3. **Local Copilot review:** `copilot --autopilot --yolo -p "/review <full branch diff vs origin/main>"`.
   Pass the WHOLE branch diff (not just uncommitted files). If the diff is too large, write it to a temp
   file and pass the file path.
4. Push only when local gates are green AND Copilot has zero comments.
5. Open the PR into the working/macro branch; set reviewer = **GitHub Copilot**; confirm the review started.
6. Wait for CI all-green AND Copilot comments.
7. If all good → merge. Otherwise fix broken tests + Copilot comments and repeat the loop.
8. Only when fully green is the task closed → next task.

Copilot review = GitHub Copilot Code Review via the PR Reviewers menu or
`gh pr edit <PR> --add-reviewer @copilot`. If that fails on `read:project`, use the GraphQL mutation
`requestReviewsByLogin` with bot login `copilot-pull-request-reviewer[bot]` (resolve the PR node id via
`gh pr view <PR> --json id`). The REST `reviewers[]=copilot` endpoint is NOT equivalent.

Do not stop after a push/review request: keep polling PR status, CI, and review comments until resolved.
If GitHub/Copilot access is unavailable, record local status + next remote step in `docs/PROGRESS.md`;
never fake the loop.

## Subagents

When parallelizing with subagents: give each a disjoint write scope, pass `docs/LESSON.md` in context,
and keep one main integrator responsible for conflicts and the final gates. Do not run broad parallel
workers over the same files.

## Finalization

When the package is complete: WOW README (model: `lopadova/AskMyDocs`), consolidate `docs/LESSON.md`
learnings into rules/skills/AGENTS.md, then tag `vX.X.X` and cut a GitHub release.
