---
title: "Budgets & Policies"
description: "Apply scoped spending limits and policy decisions."
---

# Budgets & Policies

Budgets are scoped across global, tenant, user, cost center, provider, model, agent, and purpose dimensions. Policies add declarative actions: `block`, `require_approval`, `downgrade`, `throttle`, and `queue`.

::: grids
  ::: grid
    ::: card "BudgetResolver" icon:layers
    Resolves the applicable budget tree for the envelope context and period.
    :::
  :::
  ::: grid
    ::: card "PolicyEngine" icon:shield
    Evaluates kill switches, hard budgets, and policy DSL rules.
    :::
  :::
  ::: grid
    ::: card "Approvals" icon:user-check
    Converts selected high-cost calls into a pending approval workflow.
    :::
  :::
:::

## Enforcement behavior

::: callout warning "HTTP 402" icon:ban
Hard blocks throw `BudgetExceededException` and surface as HTTP 402 with a generic public message. Internal reason details stay on the exception for logging and audit.
:::

## Policy lifecycle

::: steps
1. **Create a rule**
   Define the scope match and optional minimum-cost or model criteria.
2. **Validate**
   Use `/policies/validate` before storing a rule.
3. **Simulate**
   Use `/policies/{id}/simulate` against sample context.
4. **Audit**
   Governance model mutations are recorded in `ai_finops_audit_log`.
:::

