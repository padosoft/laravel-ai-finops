---
title: "Configuration"
description: "The first configuration keys to review after installation."
---

# Configuration

`config/ai-finops.php` is the control plane. Defaults are conservative: features are toggleable, routes are API-first, secrets are never serialized, and provider integrations are optional seams.

::: tabs
== tab "Core"
```php
'enabled' => env('AI_FINOPS_ENABLED', true),
'metering' => env('AI_FINOPS_METERING', true),
'enforcement' => env('AI_FINOPS_ENFORCEMENT', true),
```

== tab "Routes"
```php
'routes' => [
    'prefix' => env('AI_FINOPS_ROUTES_PREFIX', 'api/ai-finops'),
    'middleware' => ['api'],
    'auth_middleware' => ['auth'],
],
```

== tab "Pricing"
```php
'pricing' => [
    'sources' => ['manual', 'litellm', 'openrouter'],
    'default_winner' => ['manual', 'litellm', 'openrouter'],
    'provider_source_map' => ['openrouter' => 'openrouter', 'regolo' => 'manual'],
    'overrides_win' => true,
],
```
:::

::: callout warning "OpenRouter key handling" icon:key-round
API responses expose `has_openrouter_key`, never the configured key value. Preserve that pattern in host apps and admin panels.
:::

## Recommended first pass

::: steps
1. **Confirm route middleware**
   Use `api` routes for package tests and API clients. Add session or CSRF middleware in the host app only when needed.
2. **Set currency policy**
   Choose a base currency and an optional FX provider before enforcing budgets in another display currency.
3. **Enable pricing feeds**
   Keep manual overrides for feed-less providers such as regolo.ai, and enable OpenRouter only when the host should query it.
4. **Wire tenant context**
   Bind tenant, user, cost-center, purpose, and trace context through `TraceContext` or request metadata.
:::

