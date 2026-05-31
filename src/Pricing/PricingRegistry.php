<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing;

use Illuminate\Contracts\Config\Repository as Config;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Throwable;

/**
 * Resolves the effective price for a model across multiple feeds:
 *   1. a local Padosoft DB override (the `manual` source) WINS when present and
 *      `pricing.overrides_win` is true;
 *   2. otherwise `pricing.provider_source_map` picks the authoritative source for
 *      the call's provider ("who actually bills you");
 *   3. otherwise the enabled source with the freshest `syncedAt()` wins, ties
 *      broken by `pricing.default_winner` order.
 * Per-request memoization avoids repeated source/DB hits inside a single process.
 */
class PricingRegistry
{
    /** @var array<string,ModelPrice|null> */
    private array $memo = [];

    public function __construct(
        private readonly PricingSourceManager $manager,
        private readonly Config $config,
    ) {}

    public function priceFor(string $model, ?string $provider = null): ?ModelPrice
    {
        $key = $provider.'::'.$model;

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $override = $this->override($model, $provider);
        $base = $this->resolveFromSources($model, $provider);

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

        return array_sum($this->manager->syncAll());
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

    /**
     * Resolve a base price from the feed sources: provider_source_map first, then
     * freshest-synced among enabled feeds, ties broken by default_winner order.
     */
    private function resolveFromSources(string $model, ?string $provider): ?ModelPrice
    {
        $map = (array) $this->config->get('ai-finops.pricing.provider_source_map', []);

        if ($provider !== null && isset($map[$provider])) {
            $name = (string) $map[$provider];

            // 'manual' resolves through the currency-aware override lookup.
            if ($name === 'manual') {
                return $this->override($model, $provider);
            }

            $source = $this->manager->source($name);

            return $source !== null ? $this->fromFeed($source, $model) : null;
        }

        $winnerOrder = array_flip(array_values((array) $this->config->get('ai-finops.pricing.default_winner', [])));

        $best = null;
        $bestAt = null;
        $bestRank = PHP_INT_MAX;

        foreach ($this->manager->sources() as $source) {
            if ($source->name() === 'manual') {
                continue; // manual = override, already handled by precedence/map
            }

            $all = $source->all();
            if (! isset($all[$model]) || ! is_array($all[$model])) {
                continue;
            }

            $at = $source->syncedAt();
            $rank = $winnerOrder[$source->name()] ?? PHP_INT_MAX;

            if ($this->isFresher($at, $rank, $bestAt, $bestRank)) {
                $best = $source;
                $bestAt = $at;
                $bestRank = $rank;
            }
        }

        return $best !== null ? $this->fromFeed($best, $model) : null;
    }

    private function fromFeed(PricingSource $source, string $model): ?ModelPrice
    {
        $all = $source->all();

        if (! isset($all[$model]) || ! is_array($all[$model])) {
            return null;
        }

        return ModelPrice::fromLiteLLM($model, $all[$model], $source->name(), $source->syncedAt());
    }

    /** A later sync wins; equal/unknown freshness falls back to default_winner rank. */
    private function isFresher(?\DateTimeInterface $at, int $rank, ?\DateTimeInterface $bestAt, int $bestRank): bool
    {
        if ($at === null && $bestAt === null) {
            return $rank < $bestRank;
        }
        if ($at === null) {
            return false;
        }
        if ($bestAt === null) {
            return true;
        }
        if ($at->getTimestamp() === $bestAt->getTimestamp()) {
            return $rank < $bestRank;
        }

        return $at->getTimestamp() > $bestAt->getTimestamp();
    }
}
