<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switches
    |--------------------------------------------------------------------------
    | `enabled` gates RUNTIME behavior: route registration and (from M1) the
    | metering hook. Config and migration publishing remain available even when
    | disabled, so the package can still be installed and managed.
    | `metering` records usage; `enforcement` applies budgets/policies
    | (block/throttle/etc) — disabling it keeps observability without blocking.
    */
    'enabled' => env('AI_FINOPS_ENABLED', true),
    'metering' => env('AI_FINOPS_METERING', true),
    'enforcement' => env('AI_FINOPS_ENFORCEMENT', true),

    /*
    | Global kill switch. When true, ALL governed AI calls are blocked.
    | Scoped kill switches (per provider/tenant/feature) live in storage.
    */
    'kill_switch' => env('AI_FINOPS_KILL_SWITCH', false),

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy
    |--------------------------------------------------------------------------
    | Resolver returns the current tenant id (string|int|null). Default null
    | = single-tenant. Override with a callable/class in the host app.
    */
    'tenancy' => [
        'enabled' => env('AI_FINOPS_TENANCY', false),
        'resolver' => null, // callable|class-string resolving the current tenant id
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    */
    'currency' => [
        'base' => env('AI_FINOPS_CURRENCY', 'USD'),
        'display' => env('AI_FINOPS_DISPLAY_CURRENCY', 'EUR'),
        'fx_provider' => null, // callable|class-string returning rate(base,quote): float
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing (multi-source)
    |--------------------------------------------------------------------------
    | Multiple price feeds resolve together. A local Padosoft DB override (the
    | `manual` source) always wins when `overrides_win` is true. Otherwise the
    | per-provider `provider_source_map` decides which feed is authoritative for
    | a given provider ("who actually bills you"); when a provider is unmapped,
    | the source with the freshest `synced_at` wins, and ties fall back to the
    | `default_winner` order. NB: neither LiteLLM nor OpenRouter timestamps
    | individual prices — "freshness" is OUR last successful per-source sync.
    */
    'pricing' => [
        'overrides_win' => true, // local Padosoft DB entries (manual) override feeds

        // Enabled sources, in default-precedence order (first = highest).
        'sources' => ['manual', 'litellm', 'openrouter'],

        // Tie / unknown-freshness winner order.
        'default_winner' => ['manual', 'litellm', 'openrouter'],

        'litellm' => [
            'enabled' => env('AI_FINOPS_PRICING_LITELLM', true),
            'url' => env('AI_FINOPS_PRICING_LITELLM_URL', 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json'),
        ],

        // OpenRouter live models API. The public listing needs no key; a key
        // (never serialized) raises rate limits and unlocks per-endpoint prices
        // (Phase 2). OpenRouter passes provider list prices through WITHOUT an
        // inference markup; its ~5.5% credit top-up fee is modeled in `fees`.
        'openrouter' => [
            'enabled' => env('AI_FINOPS_PRICING_OPENROUTER', false),
            'url' => env('AI_FINOPS_PRICING_OPENROUTER_URL', 'https://openrouter.ai/api/v1/models'),
            'key' => env('AI_FINOPS_PRICING_OPENROUTER_KEY'), // optional; exposed only as has_* boolean
            'allow_keyless' => true, // the public model list works without a key
            'use_endpoints' => false, // Phase 2: per-provider endpoint prices (3–10× spread)
        ],

        'sync_cron' => env('AI_FINOPS_PRICING_SYNC_CRON', '0 4 * * *'),

        // Which source is authoritative per provider (the "who bills you" rule).
        // Unmapped providers fall through to freshest-sync / default_winner.
        'provider_source_map' => [
            'openrouter' => 'openrouter',
            'regolo' => 'manual',
            // openai/anthropic/azure/bedrock/vertex/fal_ai/… default to litellm
        ],

        // Per-provider account-level overhead applied to ESTIMATES only (what-if,
        // forecast, preflight) — never to the raw metered ledger. Percent.
        'fees' => [
            // 'openrouter' => ['markup_pct' => 5.5],
        ],

        // Recover the provider's ACTUAL billed cost that laravel/ai drops during
        // normalization (it keeps tokens only). When enabled, a global Http response
        // middleware captures usage.cost from responses whose body matches the
        // OpenRouter shape (usage.cost present) — Laravel's Http middleware does not
        // expose the request URL, so body-shape matching is used instead of host
        // filtering. The `hosts` key is reserved for documentation / future use when
        // Laravel adds request context to response middleware.
        'actual_cost' => [
            'enabled' => env('AI_FINOPS_ACTUAL_COST', false),
            'hosts' => ['openrouter.ai'], // informational — body-shape matching is active
            'store_raw' => false, // also stash the captured usage/cost block in metadata
            'openrouter' => [
                'generation_lookup' => false,   // confirm via GET /generation?id= (+1 HTTP)
                'credit_to_currency' => 1.0,    // OpenRouter credits → base currency
            ],
        ],

        // Cost cascade fallback: when a response carries neither cost nor token usage,
        // estimate tokens then apply the tariff (flagged tokens_estimated). Heuristic by
        // default; auto-upgrades to exact counts if the optional yethee/tiktoken is installed.
        'token_estimation' => [
            'enabled' => true,
            'expected_output_ratio' => 1.0, // preflight: assume output ≈ input × ratio
        ],

        'discounts' => [
            'prompt_cache' => true,
            'batch_api' => true,
            'committed_use' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | laravel/ai hook
    |--------------------------------------------------------------------------
    | The single metering point. The package degrades gracefully if laravel/ai
    | is absent (metering becomes a no-op until calls are reported manually).
    */
    'hook' => [
        'auto_register' => true,
        'estimate_preflight' => true,
        'stream_meter' => true, // live token/cost meter + mid-stream cutoff
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage / retention
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'connection' => env('AI_FINOPS_DB_CONNECTION', null),
        'table_prefix' => 'ai_finops_',
        'retention_days' => env('AI_FINOPS_RETENTION_DAYS', 730),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP / API
    |--------------------------------------------------------------------------
    | `middleware` wraps ALL package routes (default ['api'] — portable and
    | available even outside a full HTTP kernel). `auth_middleware` is applied
    | ON TOP of privileged (non-public) endpoints introduced from M1 onward;
    | only the public `health` probe omits it, and no endpoint returns secrets.
    | When serving the admin, set `middleware` => ['web'] so session + CSRF are
    | active; the admin package also adds session/CSRF wrappers under
    | `/admin/ai-finops/*`.
    */
    'routes' => [
        'enabled' => true,
        'prefix' => 'api/ai-finops',
        'middleware' => ['api'],
        'auth_middleware' => ['auth'],
    ],

    /*
    | The HTTP status returned when a hard budget/policy blocks a request.
    */
    'block_status' => 402,

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    | Records created/updated/deleted events for governance models (budgets,
    | policies, kill-switches, cost-centers, approvals, pricing overrides).
    */
    'audit' => [
        'enabled' => env('AI_FINOPS_AUDIT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature toggles (enterprise + WOW)
    |--------------------------------------------------------------------------
    */
    'features' => [
        'budgets' => true,
        'policies' => true,
        'approvals' => true,
        'chargeback' => true,
        'alerts' => true,
        'forecast' => true,
        'anomaly_detection' => true,
        'cost_aware_routing' => false, // requires eval-harness quality scores
        'whatif' => true,
        'price_watch' => true,
        'credit_pools' => false,
        'copilot' => false, // requires laravel-ai-chat / AskMyDocs
        'carbon_footprint' => true,
        'guardrail_linked_spend' => false, // requires pii-redactor / ai-act-compliance
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerting channels (configured; secrets resolved from env/storage)
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        'default_thresholds' => [50, 80, 100], // percent of budget
        'channels' => [
            'mail' => ['enabled' => true],
            'slack' => ['enabled' => false],
            'teams' => ['enabled' => false],
            'webhook' => ['enabled' => false],
            'sms' => ['enabled' => false],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Carbon / energy footprint (ESG)
    |--------------------------------------------------------------------------
    | Rough estimate: energy (kWh) = tokens/1000 × kwh_per_1k_tokens; emissions
    | (gCO2e) = energy × grid_gco2_per_kwh. Defaults are conservative placeholders;
    | tune per your providers/region.
    */
    'footprint' => [
        'kwh_per_1k_tokens' => env('AI_FINOPS_KWH_PER_1K', 0.0005),
        'grid_gco2_per_kwh' => env('AI_FINOPS_GCO2_PER_KWH', 400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrations with sibling Padosoft packages (optional, off by default)
    |--------------------------------------------------------------------------
    */
    'integrations' => [
        'eval_harness' => ['enabled' => false],
        'ai_act_compliance' => ['enabled' => false],
        'pii_redactor' => ['enabled' => false],
        'price_intelligence' => ['enabled' => false],
        'flow' => ['enabled' => false], // trace-id propagation for per-step cost
    ],
];
