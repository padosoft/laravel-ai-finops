---
title: "Quickstart"
description: "Install laravel-ai-finops and record the first governed AI call."
---

# Quickstart

This path gets an existing Laravel app from zero to a metered AI ledger.

::: steps
1. **Require the package**
   ```bash
   composer require padosoft/laravel-ai-finops
   ```
2. **Publish configuration and migrations**
   ```bash
   php artisan vendor:publish --tag=ai-finops-config
   php artisan vendor:publish --tag=ai-finops-migrations
   ```
3. **Run migrations**
   ```bash
   php artisan migrate
   ```
4. **Make a `laravel/ai` call**
   Existing calls are captured by the package listener when metering is enabled.
5. **Inspect usage**
   ```bash
   php artisan ai-finops:report --days=1
   ```
:::

::: tabs
== tab "Budget"
```php
use Padosoft\LaravelAiFinOps\Models\Budget;

Budget::create([
    'name' => 'Monthly AI cap',
    'scope_type' => 'global',
    'limit_amount' => 500,
    'currency' => 'USD',
    'period' => 'monthly',
    'soft_limit_pct' => 80,
    'hard' => true,
]);
```

== tab "Trace"
```php
app(\Padosoft\LaravelAiFinOps\Support\TraceContext::class)->within(
    [
        'trace_id' => (string) $runId,
        'agent_step' => 'summarize',
        'tenant_id' => (string) $tenantId,
    ],
    fn () => $agent->respond($prompt),
);
```
:::

::: callout warning "Pre-flight estimates" icon:triangle-alert
Reactive hard-budget enforcement blocks once the stored spend is already at or above the hard limit. Use `POST /api/ai-finops/diagnostics/estimate` when you need to check whether the next planned call would exceed a cap.
:::

