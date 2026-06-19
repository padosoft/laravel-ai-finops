---
title: "Pricing Sync"
description: "Operate pricing catalogs and source status."
---

# Pricing Sync

Run source sync when feed freshness matters for estimates, routing, and what-if analysis.

```bash
curl -X POST /api/ai-finops/pricing/sync
curl /api/ai-finops/pricing/sync/status
```

## Source status

`pricing/sync/status` reports per-source state and `has_openrouter_key`. It must not expose key values.

::: steps
1. **Sync**
   Trigger the source manager.
2. **Verify counts**
   A successful sync should return model counts and update source timestamps only when models were returned.
3. **Review manual overrides**
   Confirm feed-less providers and special enterprise rates are present.
4. **Capture watched prices**
   Use `php artisan ai-finops:capture-prices` for price-change watcher snapshots.
:::

