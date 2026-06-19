---
title: "Cost Cascade"
description: "How actual, computed, estimated, and covered cost methods work."
---

# Cost Cascade

The cascade records the truest cost available for each call.

::: steps
1. **Actual**
   Use provider-billed cost recovered from raw response usage, such as OpenRouter `usage.cost`.
2. **Computed**
   Multiply actual tokens by resolved tariff.
3. **Estimated**
   Estimate tokens from prompt and response text, then multiply by tariff.
4. **Covered**
   Apply a flat-rate subscription window and store cost as zero while preserving tokens.
:::

```mermaid
flowchart TD
  A[Call response] --> B{Actual billed cost?}
  B -->|yes| C[method actual]
  B -->|no| D{Actual tokens and tariff?}
  D -->|yes| E[method computed]
  D -->|no| F{Can estimate tokens?}
  F -->|yes| G[method estimated]
  F -->|no| H[unknown pricing]
  C --> I{Coverage window?}
  E --> I
  G --> I
  I -->|yes| J[method covered]
  I -->|no| K[ledger row]
```

::: callout tip "Audit fields" icon:file-search
Inspect `cost_method`, `tokens_estimated`, `billed_cost`, `billed_currency`, and frozen rate fields before comparing ledger values to provider invoices.
:::

