---
title: "Governance"
description: "Recommended operating model for AI spend governance."
---

# Governance

Use policy layers rather than one global switch.

::: steps
1. **Start with visibility**
   Enable metering and verify ledger rows before enforcing hard blocks.
2. **Add scoped budgets**
   Use global caps for blast-radius control and tenant or cost-center budgets for accountability.
3. **Use soft thresholds**
   Alert before blocking. Operators need time to tune routing and subscriptions.
4. **Reserve kill switches**
   Use scoped kill switches for incident response, not everyday budget management.
5. **Audit governance changes**
   Treat budgets, policies, pricing overrides, channels, and kill switches as auditable controls.
:::

::: callout tip "Recommended sequence" icon:list-checks
Visibility, soft alert, hard cap, policy simulation, then approvals. This order lowers the risk of surprising production blocks.
:::

