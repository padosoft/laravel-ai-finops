# LESSON — laravel-ai-finops

Non-obvious discoveries, API contract details, test workarounds, and lessons learned (including from
Copilot review comments). **Load this before working and pass it to every subagent.** Dated `YYYY-MM-DD`.

## 2026-05-27 — Ecosystem & architecture facts

- **Backbone = `laravel/ai`.** Verified via composer.json that Padosoft AI packages depend on the
  official `laravel/ai` SDK (`AskMyDocs`, `laravel-ai-regolo`, `laravel-ai-price-intelligence`;
  `eval-harness` opt-in). `laravel-ai-regolo` is itself a `laravel/ai` PROVIDER. ⇒ One metering hook on
  the `laravel/ai` lifecycle sees every provider's calls.
- **`laravel-ai-chat` is a DEMO only** (laravel/ai + Vercel AI SDK showcase). Do not treat it as a
  capability to integrate.
- **`agentic-qa-kit` is NOT a Laravel package.** It's a Bun/TypeScript monorepo (`@aqa/*` workspaces,
  Biome, Playwright, packs). No composer.json. Integrate as an EXTERNAL QA runner that drives the app
  over HTTP; correlate cost to scenarios via a `trace-id` request header.
- **`laravel-flow` and `laravel-ai-search-providers` do not call LLMs directly** (orchestrator / web
  search). Not metering gaps; flow is where we propagate `trace-id` for per-step cost attribution.
- **Two third-party inspirations to beat:** `subhashladumor1/laravel-ai-guard` (enforcement + static
  pricing tables that go stale) and `jonaaix/laravel-ai-costs` (tracking only, pricing via LiteLLM DB,
  no budget/alert/block). We unify tracking + enforcement + governance with dynamic pricing.
- **Reference governance repo** `../product_image_discovery_admin`: React 18 + Vite (plain CSS, no
  Tailwind, no TS), Herd PHP via `npm run phpunit` → `scripts/run-php.mjs`, Playwright projects
  desktop(1440×900)+tablet(1024×768), phpunit Feature/Unit suites. We modernize the admin to
  React+Vite+**Tailwind** (latest) per Lorenzo's request.
- **Decisions (2026-05-27, Lorenzo):**
  1. **Pricing source = BOTH.** Mirror the LiteLLM pricing DB AS the base; a Padosoft local DB entry,
     if present, OVERRIDES and wins. (`PricingRegistry`: LiteLLM base ⊕ Padosoft overrides.)
  2. **`laravel/ai` compat:** keep `^0.6.8 || ^0.7` if feasible (abstract the hook behind an adapter);
     otherwise target the latest available version.
  3. **Versions:** React / Vite / Tailwind = latest stable (currently React 19, Vite 8, Tailwind 4 —
     pin actual latest at scaffold time). Laravel 13.x. PHP `^8.3` to also cover 8.4 and **8.5**.
- **Copilot reviewer gotcha:** `gh pr edit --add-reviewer @copilot` can fail on `read:project`; fall back
  to GraphQL `requestReviewsByLogin` with bot `copilot-pull-request-reviewer[bot]`. REST
  `reviewers[]=copilot` is not equivalent (200 without a real review request). On PR #1 `gh pr edit`
  DID work (exit 0) and `requested_reviewers` showed the Copilot bot — verify with
  `gh api repos/<owner>/<repo>/pulls/<n>/requested_reviewers`.
- **Copilot posts inline comments, not a formal review object** (PR #1): poll
  `gh api .../pulls/<n>/comments` (count), not just `gh pr view --json reviews` (which stayed empty).
- **Package routes must default to `['api']`, NOT `['web']`.** The `web` middleware group is not
  registered in the Orchestra Testbench package context → requests error at Kernel middleware resolution.
  Pattern: default `['api']`, add `auth_middleware` (['auth']) on privileged endpoints, and let the
  admin/host set `['web']` where session+CSRF exist. (Copilot P1 on PR #1.)
- **Keep the plan in-repo (`docs/PLAN.md`).** `%USERPROFILE%`/`~/.claude` paths are not portable for
  other contributors/CI (Copilot flagged 4× on PR #1). Reference `docs/PLAN.md` + note the local copy.
- **CI: PHP 8.5 passed too** on `shivammathur/setup-php@v2` — the experimental (non-blocking) 8.5 job
  was green for the M0 scaffold.
