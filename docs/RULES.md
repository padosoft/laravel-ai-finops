# Project Rules — laravel-ai-finops

## Source Of Truth

- Canonical plan: `%USERPROFILE%\.claude\plans\allora-leggi-questi-repo-polished-hearth.md`.
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
