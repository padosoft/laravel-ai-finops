---
title: "Modello dati/contratto"
description: "Envelope, ledger, and governance model contracts."
---

# Modello dati/contratto

## AiCallEnvelope

`AiCallEnvelope` is the cross-package contract. It is provider-neutral and designed for agentic attribution.

| Area | Fields |
| --- | --- |
| Provider | provider, model, modality |
| Usage | input, output, cached, media units |
| Cost | input, output, total, currency, method |
| Context | tenant, user, cost center, purpose |
| Agent | trace id, agent step |
| Governance | status, policy metadata |

## Ledger tables

::: grids
  ::: grid
    ::: card "usage ledger" icon:database
    Immutable call rows with pricing provenance and billed cost fields.
    :::
  :::
  ::: grid
    ::: card "budgets" icon:wallet
    Scoped limits, periods, soft thresholds, and hard enforcement.
    :::
  :::
  ::: grid
    ::: card "policies" icon:scroll-text
    Declarative rules and actions for spend governance.
    :::
  :::
:::

## Contract gotcha

::: callout warning "Nullable scope IDs" icon:database-zap
Composite uniques with nullable scope IDs can be racy across databases. The package rules prefer explicit sentinels where uniqueness requires equality.
:::

