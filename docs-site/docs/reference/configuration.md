---
title: "Configuration Reference"
description: "Important config keys in config/ai-finops.php."
---

# Configuration Reference

| Key | Purpose |
| --- | --- |
| `enabled` | Master package toggle |
| `metering` | Capture usage rows |
| `enforcement` | Apply policy and budget blocks |
| `routes.prefix` | API route prefix |
| `routes.middleware` | Base route middleware |
| `routes.auth_middleware` | Privileged endpoint middleware |
| `currency.base` | Stored ledger currency |
| `pricing.sources` | Enabled source order |
| `pricing.default_winner` | Tie-break order |
| `pricing.provider_source_map` | Billing authority by provider |
| `pricing.overrides_win` | Manual override precedence |
| `pricing.openrouter` | OpenRouter sync options |
| `pricing.subscription-windows` | Flat-rate coverage API surface |
| `pricing.actual_cost` | Raw cost recovery options |
| `pricing.token_estimation` | Estimator behavior |
| `features.*` | Feature toggles |
| `integrations.*` | Optional package seams |
| `alerts.*` | Alert channel and threshold defaults |
| `retention.*` | Ledger retention settings |

::: callout warning "Base currency" icon:badge-dollar-sign
Aggregated stored totals should report the base currency unless an FX conversion has actually been applied.
:::

