---
title: "PHP API"
description: "Core PHP classes, models, and contracts."
---

# PHP API

## Value objects and support

| Class | Purpose |
| --- | --- |
| `Data\AiCallEnvelope` | Provider-neutral call contract |
| `Data\TokenUsage` | Token and cache usage |
| `Data\CostBreakdown` | Input, output, and total cost |
| `Support\TraceContext` | Scoped agent and tenant context |
| `Support\TenantResolver` | Tenant resolution seam |
| `Support\FxConverter` | Base/display currency conversion |

## Contracts

| Contract | Purpose |
| --- | --- |
| `UsageRecorder` | Persist usage rows |
| `PricingSource` | Feed or override source |
| `ActualCostResolver` | Recover provider-billed cost |
| `TokenEstimator` | Estimate tokens when provider usage is absent |
| `QualityScoreProvider` | Cost-aware routing quality seam |
| `GuardrailProvider` | Compliance and redaction seam |
| `CopilotProvider` | Natural-language FinOps seam |

## Models

Budget, UsageRecord, PricingOverride, SubscriptionWindow, Policy, SpendApproval, KillSwitch, CostCenter, AlertChannel, AlertRule, AlertLogEntry, AuditEntry, RoutingRule, WhatIfScenario, CreditPool, CreditTransaction, PriceWatchSubscription, PriceSnapshot, CopilotQuery, and AnomalyAck.

::: callout tip "Null defaults" icon:plug-zap
Optional integrations bind null providers by default. Host apps replace them only when they need quality scores, guardrails, or copilot behavior.
:::

