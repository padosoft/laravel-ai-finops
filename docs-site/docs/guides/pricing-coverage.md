---
title: "Pricing & Coverage"
description: "Use multi-source pricing, manual overrides, and flat-rate windows."
---

# Pricing & Coverage

Pricing resolves from three source classes:

| Source | Purpose |
| --- | --- |
| `manual` | Local overrides and feed-less providers such as regolo.ai |
| `litellm` | Broad model price catalog |
| `openrouter` | Live OpenRouter catalog and billing authority for OpenRouter-routed calls |

Resolution order is fixed:

::: steps
1. **Manual override**
   If enabled, an exact local override wins.
2. **Provider source map**
   Use the configured source for whoever actually bills the call.
3. **Freshest sync**
   Compare source-level `syncedAt()` values.
4. **Default winner**
   Break unknown or tied freshness by configured source order.
:::

## Subscription windows

Flat-rate subscriptions, canoni, and provider plans are modeled as coverage windows. Covered calls store tokens and provenance while cost is zeroed.

::: callout tip "Ledger truth" icon:receipt
Coverage changes the metered FinOps cost to zero for the active window. Provider billed or would-be values remain available through method/provenance fields where captured.
:::

## Estimate-only fees

Provider overhead such as OpenRouter credit-funding markup belongs in estimates and what-if calculations. It must not mutate raw ledger rows.

