<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing;

use Illuminate\Contracts\Config\Repository as Config;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Throwable;

/**
 * Resolves the effective price for a model: the LiteLLM mirror is the base, and a
 * local Padosoft override (DB) WINS when present (config pricing.overrides_win).
 * Per-request memoization avoids repeated source/DB hits inside a single process.
 */
class PricingRegistry
{
    /** @var array<string,ModelPrice|null> */
    private array $memo = [];

    public function __construct(
        private readonly PricingSource $source,
        private readonly Config $config,
    ) {}

    public function priceFor(string $model, ?string $provider = null): ?ModelPrice
    {
        $key = $provider.'::'.$model;

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $override = $this->override($model, $provider);
        $base = $this->fromSource($model);

        $overridesWin = (bool) $this->config->get('ai-finops.pricing.overrides_win', true);

        $price = match (true) {
            $override !== null && ($overridesWin || $base === null) => $override,
            $base !== null => $base,
            default => $override, // overrides_win=false but no base → still use override
        };

        return $this->memo[$key] = $price;
    }

    public function sync(): int
    {
        $this->memo = [];

        return $this->source->sync();
    }

    private function override(string $model, ?string $provider): ?ModelPrice
    {
        try {
            $query = PricingOverride::query()->where('model', $model);

            // Prefer an exact provider match, then a provider-agnostic (null) row.
            $row = (clone $query)->where('provider', $provider)->first()
                ?? (clone $query)->whereNull('provider')->first();
        } catch (Throwable) {
            return null; // table not migrated yet / DB unavailable
        }

        return $row?->toModelPrice();
    }

    private function fromSource(string $model): ?ModelPrice
    {
        $all = $this->source->all();

        if (! isset($all[$model]) || ! is_array($all[$model])) {
            return null;
        }

        return ModelPrice::fromLiteLLM($model, $all[$model], $this->source->name());
    }
}
