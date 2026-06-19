---
title: "Architecture Overview"
description: "Major package components and their boundaries."
---

# Architecture Overview

The package separates capture, policy, pricing, ledger, analytics, and optional integrations.

```mermaid
flowchart TB
  subgraph Capture
    LAI[laravel/ai events]
    TC[TraceContext]
    ENV[AiCallEnvelope]
  end
  subgraph Governance
    PE[PolicyEngine]
    BR[BudgetResolver]
    KS[KillSwitch]
  end
  subgraph Pricing
    PM[PricingSourceManager]
    PR[PricingRegistry]
    CR[CostResolutionService]
  end
  subgraph Records
    UL[UsageRecord]
    AU[AuditEntry]
  end
  LAI --> ENV
  TC --> ENV
  ENV --> PE
  PE --> BR
  ENV --> CR
  PM --> PR
  PR --> CR
  CR --> UL
  PE --> AU
```

## Boundaries

| Layer | Owns |
| --- | --- |
| `Metering` | Event listening and envelope construction |
| `Policies` | Kill switches, budgets, and policy decisions |
| `Pricing` | Price source sync, resolution, and cost calculation |
| `Ledger` | Append-only usage persistence |
| `Http\Controllers` | API surface consumed by admins and automation |
| `Contracts` | Optional seams for quality, guardrails, copilot, token estimation, and actual cost |

