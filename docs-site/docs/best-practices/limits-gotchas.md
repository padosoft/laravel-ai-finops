---
title: "Limits & Gotchas"
description: "Known limitations and operational traps."
---

# Limits & Gotchas

::: callout danger "Do not confuse unknown with free" icon:octagon-alert
Unknown pricing must not become zero. Zero is reserved for covered calls, free tiers, or explicit zero-rate tariffs.
:::

## Gotchas

::: collapsible "Prospective enforcement needs estimates"
The engine can block when stored spend is already over a hard limit. To block a call that would exceed the limit, pre-compute an estimate and include it in the decision path.
:::

::: collapsible "Provider invoices may not equal computed splits"
Actual billed cost can be captured as a total while input/output splits remain tariff-derived for analytics. Do not require split totals to match provider billed total exactly.
:::

::: collapsible "OpenRouter overhead is estimate-only"
Credit funding fees or account-level markups should be modeled in estimates and what-if analysis, not rewritten into raw usage rows.
:::

::: collapsible "Package routes should default to api"
The package test context does not register Laravel's `web` group. Keep package defaults API-first and let host apps add web/session middleware around admin screens.
:::

::: collapsible "PowerShell filter quoting"
`--filter "A|B"` can be interpreted as a pipe in PowerShell. Run one filter at a time or the full suite.
:::

