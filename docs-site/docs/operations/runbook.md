---
title: "Runbook"
description: "Routine operations for a laravel-ai-finops installation."
---

# Runbook

## Daily

::: steps
1. **Check health**
   Call `GET /api/ai-finops/health`.
2. **Review spend**
   Run `php artisan ai-finops:report --days=1`.
3. **Review alert logs**
   Check `/alerts/log` for repeated threshold events or delivery failures.
4. **Inspect anomalies**
   Check `/anomalies` and acknowledge reviewed anomalies.
:::

## Weekly

Run pricing sync, review overrides, check cost-center allocation, and prune only according to your retention policy.

```bash
php artisan ai-finops:capture-prices
php artisan ai-finops:check-alerts
```

::: callout warning "Prune carefully" icon:trash-2
`ai-finops:prune` removes old ledger data according to retention. Export or archive first when finance teams require longer evidence.
:::

