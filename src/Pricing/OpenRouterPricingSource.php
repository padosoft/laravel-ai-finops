<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;

/**
 * Pricing fed by the OpenRouter live models API (https://openrouter.ai/api/v1/models).
 * OpenRouter passes provider list prices through WITHOUT an inference markup, so the
 * per-token figures map directly. Responses are normalized into the LiteLLM-style
 * attribute map so ModelPrice::fromLiteLLM keeps working. The public list needs no
 * key; a configured key (never serialized) only raises limits / unlocks endpoints.
 * Network failures degrade gracefully to whatever is cached.
 */
class OpenRouterPricingSource implements PricingSource
{
    private const CACHE_KEY = 'ai-finops:pricing:openrouter';

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
            'ai-finops.pricing.openrouter.url',
            'https://openrouter.ai/api/v1/models'
        );

        try {
            $request = $this->http->acceptJson();
            $key = $this->config->get('ai-finops.pricing.openrouter.key');
            if (is_string($key) && $key !== '') {
                $request = $request->withToken($key);
            }
            $response = $request->get($url);
        } catch (\Throwable) {
            return 0;
        }

        if (! $response->successful()) {
            return 0;
        }

        $rows = $response->json('data');

        if (! is_array($rows)) {
            return 0;
        }

        $map = [];
        foreach ($rows as $model) {
            if (! is_array($model) || ! isset($model['id']) || ! is_string($model['id'])) {
                continue;
            }
            $map[$model['id']] = $this->normalize($model);
        }

        $this->cache->forever(self::CACHE_KEY, $map);
        $this->cache->forever(self::SYNCED_AT_KEY, now()->toIso8601String());

        return count($map);
    }

    public function name(): string
    {
        return 'openrouter';
    }

    public function syncedAt(): ?\DateTimeInterface
    {
        $at = $this->cache->get(self::SYNCED_AT_KEY);

        return is_string($at) ? CarbonImmutable::parse($at) : null;
    }

    /**
     * Map an OpenRouter model entry to the LiteLLM-style attribute array.
     *
     * @param  array<string,mixed>  $model
     * @return array<string,mixed>
     */
    private function normalize(array $model): array
    {
        $pricing = is_array($model['pricing'] ?? null) ? $model['pricing'] : [];

        $attr = [
            'input_cost_per_token' => isset($pricing['prompt']) ? (float) $pricing['prompt'] : null,
            'output_cost_per_token' => isset($pricing['completion']) ? (float) $pricing['completion'] : null,
            'cache_read_input_token_cost' => isset($pricing['input_cache_read']) ? (float) $pricing['input_cache_read'] : null,
            'cache_creation_input_token_cost' => isset($pricing['input_cache_write']) ? (float) $pricing['input_cache_write'] : null,
            'litellm_provider' => $this->provider($model),
            'mode' => 'chat',
        ];

        return array_filter($attr, static fn ($value) => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $model
     */
    private function provider(array $model): ?string
    {
        $top = $model['top_provider'] ?? null;
        if (is_array($top) && isset($top['provider']) && is_string($top['provider'])) {
            return $top['provider'];
        }

        $id = (string) $model['id'];
        $prefix = explode('/', $id)[0] ?? '';

        return $prefix !== '' ? $prefix : null;
    }

    private function enabled(): bool
    {
        if (! (bool) $this->config->get('ai-finops.pricing.openrouter.enabled', false)) {
            return false;
        }

        $key = $this->config->get('ai-finops.pricing.openrouter.key');

        return (bool) $this->config->get('ai-finops.pricing.openrouter.allow_keyless', true)
            || (is_string($key) && $key !== '');
    }
}
