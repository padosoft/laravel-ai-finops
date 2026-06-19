---
title: "API"
description: "HTTP API endpoint reference."
---

# API

Default prefix: `/api/ai-finops`.

## Public

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/health` | Package health probe |

## Usage and pricing

| Method | Path |
| --- | --- |
| GET | `/usage` |
| GET | `/usage/{id}` |
| GET | `/usage/{traceId}/trace` |
| POST | `/diagnostics/estimate` |
| GET | `/pricing/models` |
| POST | `/pricing/sync` |
| GET | `/pricing/sync/status` |
| GET, POST | `/pricing/overrides` |
| PUT, DELETE | `/pricing/overrides/{id}` |
| GET, POST | `/pricing/subscription-windows` |
| PUT, DELETE | `/pricing/subscription-windows/{id}` |

## Governance

| Method | Path |
| --- | --- |
| GET, POST | `/budgets` |
| GET | `/budgets/tree` |
| GET, PUT, DELETE | `/budgets/{id}` |
| GET | `/budgets/{id}/burndown` |
| GET, POST | `/policies` |
| GET, PUT, DELETE | `/policies/{id}` |
| POST | `/policies/{id}/simulate` |
| POST | `/policies/validate` |
| GET | `/approvals` |
| GET | `/approvals/{id}` |
| POST | `/approvals/{id}/approve` |
| POST | `/approvals/{id}/reject` |

## FinOps features

| Area | Paths |
| --- | --- |
| Dashboard | `/dashboard/kpis`, `/dashboard/spend-trend`, `/dashboard/top-models`, `/dashboard/top-tenants`, `/dashboard/budget-burn`, `/dashboard/anomalies` |
| Chargeback | `/cost-centers`, `/cost-centers/{id}`, `/chargeback/report` |
| Routing | `/routing/rules`, `/routing/rules/{id}`, `/routing/quality-scores`, `/routing/simulate` |
| Forecast | `/forecast`, `/forecast/{budgetId}`, `/anomalies`, `/anomalies/{id}/ack` |
| What-if | `/whatif/simulate`, `/whatif/scenarios`, `/whatif/scenarios/{id}` |
| Footprint | `/footprint/summary`, `/footprint/trend` |
| Credits | `/credits/pools`, `/credits/pools/{id}`, `/credits/pools/{id}/topup`, `/credits/pools/{id}/ledger` |
| Alerts | `/alerts/channels`, `/alerts/rules`, `/alerts/log` |
| Price watch | `/price-watch/changes`, `/price-watch/subscriptions` |
| Copilot | `/copilot/query`, `/copilot/history` |
| Audit and settings | `/audit`, `/settings`, `/settings/kill-switch` |

::: callout info "Authentication" icon:shield
All privileged endpoints use `auth_middleware` from configuration. The exact guard depends on the host app.
:::

