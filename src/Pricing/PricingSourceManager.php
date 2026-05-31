<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing;

use Illuminate\Contracts\Config\Repository as Config;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;

/**
 * Holds every available PricingSource and exposes the enabled subset in the
 * configured precedence order (`ai-finops.pricing.sources`). Provides the merged
 * catalog (for listing) and a per-source sync. Price RESOLUTION lives in
 * PricingRegistry, which consults these sources.
 */
class PricingSourceManager
{
    /** @param array<string,PricingSource> $sources keyed by source name */
    public function __construct(
        private readonly array $sources,
        private readonly Config $config,
    ) {}

    /**
     * Enabled sources, in configured precedence order (first = highest). Unknown
     * names in config are ignored; a name maps to its source by `name()`.
     *
     * @return array<int,PricingSource>
     */
    public function sources(): array
    {
        $order = (array) $this->config->get('ai-finops.pricing.sources', ['manual', 'litellm', 'openrouter']);

        $enabled = [];
        foreach ($order as $name) {
            if (isset($this->sources[$name])) {
                $enabled[] = $this->sources[$name];
            }
        }

        return $enabled;
    }

    /** Find an enabled source by name, or null. */
    public function source(string $name): ?PricingSource
    {
        foreach ($this->sources() as $source) {
            if ($source->name() === $name) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Union catalog across enabled sources, each model tagged with `_source`.
     * The first source listed wins on a model-id collision (precedence order).
     *
     * @return array<string,array<string,mixed>>
     */
    public function merged(): array
    {
        $merged = [];
        foreach ($this->sources() as $source) {
            foreach ($source->all() as $model => $attr) {
                if (isset($merged[$model])) {
                    continue; // earlier (higher-precedence) source already won
                }
                if (! is_array($attr)) {
                    continue;
                }
                $attr['_source'] = $source->name();
                $merged[$model] = $attr;
            }
        }

        return $merged;
    }

    /**
     * Sync every enabled source.
     *
     * @return array<string,int> source name → model count after sync
     */
    public function syncAll(): array
    {
        $counts = [];
        foreach ($this->sources() as $source) {
            $counts[$source->name()] = $source->sync();
        }

        return $counts;
    }
}
