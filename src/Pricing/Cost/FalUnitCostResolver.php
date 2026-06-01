<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing\Cost;

use Padosoft\LaravelAiFinOps\Contracts\ActualCostResolver;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Throwable;

/**
 * Unit-priced cost for media providers (e.g. fal.ai) that bill per second /
 * image / megapixel / request and return no tokens and no cost. The rate comes
 * from a manual PricingOverride (unit + unit_rate); the quantity comes from the
 * call metadata (`inference_time`, `images`/`image_count`, `megapixels`). Returns
 * null when no unit override or quantity is available → cascade falls back.
 */
class FalUnitCostResolver implements ActualCostResolver
{
    public function resolve(AiCallEnvelope $call): ?ResolvedActualCost
    {
        try {
            $base = PricingOverride::query()->where('model', $call->model)->whereNotNull('unit_rate');

            // Prefer an exact provider match, then a provider-agnostic (null) row.
            $override = (clone $base)->where('provider', $call->provider)->first()
                ?? (clone $base)->whereNull('provider')->first();
        } catch (Throwable) {
            return null;
        }

        $rate = $override?->unitRate();
        if ($rate === null) {
            return null;
        }

        $quantity = $this->quantity((string) $override->unit, $call->metadata);
        if ($quantity === null) {
            return null;
        }

        return new ResolvedActualCost(
            amount: $rate * $quantity,
            currency: (string) ($override->currency ?? 'USD'),
            tokens: null, // media: no tokens
            source: 'fal',
        );
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function quantity(string $unit, array $metadata): ?float
    {
        return match ($unit) {
            'per_second' => isset($metadata['inference_time']) ? (float) $metadata['inference_time'] : null,
            'per_image' => isset($metadata['images']) ? (float) (is_countable($metadata['images']) ? count($metadata['images']) : $metadata['images'])
                : (isset($metadata['image_count']) ? (float) $metadata['image_count'] : null),
            'per_megapixel' => isset($metadata['megapixels']) ? (float) $metadata['megapixels'] : null,
            'per_request' => 1.0,
            default => null,
        };
    }
}
