---
title: "CLI"
description: "Artisan command reference."
---

# CLI

| Command | Purpose |
| --- | --- |
| `ai-finops:report --days=30` | Print a spend summary for the period |
| `ai-finops:prune --days=730` | Remove usage rows older than retention |
| `ai-finops:check-alerts` | Evaluate alert thresholds |
| `ai-finops:capture-prices` | Snapshot watched model prices |

## Examples

```bash
php artisan ai-finops:report --days=7
php artisan ai-finops:check-alerts
php artisan ai-finops:capture-prices
php artisan ai-finops:prune --days=730
```

::: callout warning "Schedule deliberately" icon:calendar-clock
Run alert checks and price capture on a schedule that matches your reporting needs. Avoid pruning schedules that conflict with finance retention.
:::

