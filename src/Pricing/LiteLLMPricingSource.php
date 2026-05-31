<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;

/**
 * Pricing mirror backed by the LiteLLM model price database (2,600+ models).
 * The fetched map is cached; `sync()` forces a refresh. Network failures degrade
 * gracefully to whatever is cached (possibly empty).
 */
class LiteLLMPricingSource implements PricingSource
{
    private const CACHE_KEY = 'ai-finops:pricing:litellm';

    private const SYNCED_AT_KEY = self::CACHE_KEY.':synced_at';

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    public function all(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $this->sync();

        $cached = $this->cache->get(self::CACHE_KEY);

        return is_array($cached) ? $cached : [];
    }

    public function sync(): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $url = (string) $this->config->get(
            'ai-finops.pricing.litellm.url',
            'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json'
        );

        try {
            $response = $this->http->get($url);
        } catch (\Throwable) {
            return 0;
        }

        if (! $response->successful()) {
            return 0;
        }

        $data = $response->json();

        if (! is_array($data)) {
            return 0;
        }

        // The LiteLLM file includes a non-model "sample_spec" entry; drop it.
        unset($data['sample_spec']);

        $this->cache->forever(self::CACHE_KEY, $data);
        $this->cache->forever(self::SYNCED_AT_KEY, now()->toIso8601String());

        return count($data);
    }

    public function name(): string
    {
        return 'litellm';
    }

    public function syncedAt(): ?\DateTimeInterface
    {
        $at = $this->cache->get(self::SYNCED_AT_KEY);

        return is_string($at) ? CarbonImmutable::parse($at) : null;
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('ai-finops.pricing.litellm.enabled', true);
    }
}
