<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing;

use Carbon\CarbonImmutable;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Throwable;

/**
 * The manual price source: Padosoft local DB overrides surfaced as a first-class
 * feed (`manual`). Covers providers with no public price API (e.g. regolo.ai),
 * supporting per-1M / EUR entry. Note: actual price RESOLUTION still flows through
 * PricingRegistry's currency-aware override lookup; this adapter exists so the
 * manual entries appear in the merged catalog and per-source status.
 */
class ManualPricingSource implements PricingSource
{
    public function all(): array
    {
        try {
            $rows = PricingOverride::query()->get();
        } catch (Throwable) {
            return []; // table not migrated yet / DB unavailable
        }

        $map = [];
        foreach ($rows as $row) {
            $price = $row->toModelPrice();
            $map[$row->model] = array_filter([
                'input_cost_per_token' => $price->inputPerToken,
                'output_cost_per_token' => $price->outputPerToken,
                'cache_read_input_token_cost' => $price->cacheReadPerToken,
                'cache_creation_input_token_cost' => $price->cacheWritePerToken,
                'litellm_provider' => $price->provider,
                'currency' => $price->currency,
                'mode' => 'chat',
            ], static fn ($value) => $value !== null);
        }

        return $map;
    }

    public function sync(): int
    {
        try {
            return PricingOverride::query()->count();
        } catch (Throwable) {
            return 0;
        }
    }

    public function name(): string
    {
        return 'manual';
    }

    public function syncedAt(): ?\DateTimeInterface
    {
        try {
            $latest = PricingOverride::query()->max('updated_at');
        } catch (Throwable) {
            return null;
        }

        return $latest !== null ? CarbonImmutable::parse($latest) : null;
    }
}
