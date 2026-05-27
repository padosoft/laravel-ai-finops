<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switches
    |--------------------------------------------------------------------------
    | `enabled` turns the whole package on/off. `metering` records usage,
    | `enforcement` applies budgets/policies (block/throttle/etc). Disabling
    | enforcement keeps observability without ever blocking a call.
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
    | Pricing
    |--------------------------------------------------------------------------
    | Source order: the LiteLLM-style mirror is the BASE; a Padosoft local DB
    | override entry, when present, WINS. Sync refreshes the mirror.
    */
    'pricing' => [
        'litellm' => [
            'enabled' => env('AI_FINOPS_PRICING_LITELLM', true),
            'url' => env('AI_FINOPS_PRICING_LITELLM_URL', 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json'),
            'sync_cron' => env('AI_FINOPS_PRICING_SYNC_CRON', '0 4 * * *'),
        ],
        'overrides_win' => true, // local Padosoft DB entries override the mirror
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
    */
    'routes' => [
        'enabled' => true,
        'prefix' => 'api/ai-finops',
        'middleware' => ['api'],
    ],

    /*
    | The HTTP status returned when a hard budget/policy blocks a request.
    */
    'block_status' => 402,

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
