---
title: "ADR"
description: "Architecture decision records for laravel-ai-finops."
---

# ADR

::: collapsible "ADR-001: use laravel/ai as the backbone"
Status: accepted.

The package hooks the official `laravel/ai` lifecycle rather than provider SDKs. This captures all compatible providers and keeps governance code independent from individual SDK changes.
:::

::: collapsible "ADR-002: immutable ledger first"
Status: accepted.

Every usage record is appended and later aggregated. Derived reports are reproducible, audit-friendly, and can evolve without losing original metering facts.
:::

::: collapsible "ADR-003: multi-source pricing"
Status: accepted.

Pricing combines manual overrides, LiteLLM, and OpenRouter. The resolver chooses manual overrides, provider authority, freshest sync, then configured tie-break.
:::

::: collapsible "ADR-004: recover actual cost without forking laravel/ai"
Status: accepted.

The package can capture selected raw HTTP usage cost metadata before `laravel/ai` normalizes responses to tokens. It does not persist prompt content.
:::

::: collapsible "ADR-005: optional integration seams"
Status: accepted.

Quality score, guardrail, and copilot integrations are contracts with null defaults, not hard dependencies.
:::

::: callout warning "ADR maintenance" icon:file-warning
Update this page whenever a package boundary changes. Stale ADRs are worse than no ADRs because they mislead future operators.
:::

