---
title: "Security & Privacy"
description: "Protect secrets and sensitive usage context."
---

# Security & Privacy

## Rules

| Area | Practice |
| --- | --- |
| Provider secrets | Expose `has_*` booleans only |
| Prompt content | Do not persist prompt bodies in the ledger |
| Raw provider response | Capture only billing metadata where supported |
| Admin routes | Use host app auth middleware |
| Audit | Redact sensitive keys and webhook values |

::: callout warning "No secret previews" icon:lock-keyhole
Do not return partial keys, webhook previews, or token fragments to clients. A boolean presence flag is enough for operators.
:::

## Guardrail seam

`GuardrailProvider` is an optional integration seam for packages such as PII redaction or AI Act compliance controls. Keep it optional so core metering works in every host app.

