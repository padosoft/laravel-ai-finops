---
title: "Worked Example"
description: "A complete tenant budget and trace-cost workflow."
---

# Worked Example

This example meters an agent run for tenant `acme`, blocks overspend, and later reports cost by step.

::: steps
1. **Create a tenant budget**
   ```php
   Budget::create([
       'name' => 'Acme daily cap',
       'scope_type' => 'tenant',
       'scope_id' => 'acme',
       'limit_amount' => 50,
       'currency' => 'USD',
       'period' => 'daily',
       'soft_limit_pct' => 80,
       'hard' => true,
   ]);
   ```
2. **Attach context**
   ```php
   app(TraceContext::class)->within([
       'tenant_id' => 'acme',
       'cost_center' => 'support',
       'trace_id' => 'ticket-4832',
       'agent_step' => 'classify',
       'purpose' => 'customer-support',
   ], fn () => $agent->respond($prompt));
   ```
3. **Read the trace**
   ```bash
   curl /api/ai-finops/usage/ticket-4832/trace
   ```
4. **Report the tenant**
   ```bash
   php artisan ai-finops:report --days=1
   ```
:::

::: collapsible "Expected ledger shape"
Rows include provider, model, tokens, `cost_method`, `tokens_estimated`, `billed_cost`, `billed_currency`, frozen price source, tenant, cost center, trace id, and agent step.
:::

::: callout danger "Do not use zero for unknown price" icon:octagon-alert
If a model has no price and cannot be estimated, return an unknown or null result. A zero cost means covered, not unknown.
:::

