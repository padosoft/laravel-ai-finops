<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing\Cost;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Padosoft\LaravelAiFinOps\Contracts\ActualCostResolver;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Throwable;

/**
 * Actual cost for OpenRouter-routed calls, recovered from the RawResponseCapture
 * (OpenRouter `usage.cost`, in credits) and converted to the base currency. When
 * `generation_lookup` is enabled and a generation id was captured, it confirms the
 * authoritative USD `total_cost` via GET /api/v1/generation?id=.
 */
class OpenRouterCostResolver implements ActualCostResolver
{
    public function __construct(
        private readonly RawResponseCapture $capture,
        private readonly Config $config,
        private readonly Http $http,
    ) {}

    public function resolve(AiCallEnvelope $call): ?ResolvedActualCost
    {
        $sum = $this->capture->sumCost();

        if ($sum === null) {
            return null;
        }

        $base = (string) $this->config->get('ai-finops.currency.base', 'USD');
        $rate = (float) $this->config->get('ai-finops.pricing.actual_cost.openrouter.credit_to_currency', 1.0);

        $amount = $sum['cost'] * $rate;
        $tokens = $sum['tokens']; // sumCost() always returns a TokenUsage

        // Optional authoritative confirmation via the generation endpoint (USD).
        if ((bool) $this->config->get('ai-finops.pricing.actual_cost.openrouter.generation_lookup', false)) {
            $confirmed = $this->generationLookup($sum['id'] ?? null);
            if ($confirmed !== null) {
                $amount = $confirmed;
            }
        }

        return new ResolvedActualCost(
            amount: $amount,
            currency: $base,
            tokens: $tokens,
            source: 'openrouter',
        );
    }

    private function generationLookup(?string $id): ?float
    {
        if ($id === null || $id === '') {
            return null;
        }

        $url = rtrim((string) $this->config->get('ai-finops.pricing.openrouter.url', 'https://openrouter.ai/api/v1'), '/');
        $key = $this->config->get('ai-finops.pricing.openrouter.key');

        try {
            $request = $this->http->acceptJson();
            if (is_string($key) && $key !== '') {
                $request = $request->withToken($key);
            }
            $response = $request->get($url.'/generation', ['id' => $id]);

            if (! $response->successful()) {
                return null;
            }

            $total = $response->json('data.total_cost');

            return is_numeric($total) ? (float) $total : null;
        } catch (Throwable) {
            return null;
        }
    }
}
