<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Routing;

use Padosoft\LaravelAiFinOps\Contracts\QualityScoreProvider;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;

/**
 * Cost-aware "quality-per-dollar" routing: among candidate models, pick the
 * cheapest one that meets a minimum quality bar (quality from the bound
 * QualityScoreProvider / eval-harness). Models with unknown quality stay eligible
 * but are flagged, so a missing eval-harness integration degrades to cheapest.
 */
class RoutingEngine
{
    public function __construct(
        private readonly PricingRegistry $pricing,
        private readonly QualityScoreProvider $quality,
    ) {}

    /**
     * @param  array<int,string>  $candidates
     * @return array<string,mixed>
     */
    public function recommend(array $candidates, ?float $minQuality = null, ?string $provider = null): array
    {
        $evaluated = [];

        foreach ($candidates as $model) {
            $price = $this->pricing->priceFor($model, $provider);
            $costMetric = $price ? ($price->inputPerToken + $price->outputPerToken) : null;
            $score = $this->quality->scoreFor($model);

            $eligible = $minQuality === null || $score === null || $score >= $minQuality;

            $evaluated[] = [
                'model' => $model,
                'cost_metric' => $costMetric,
                'quality' => $score,
                'eligible' => $eligible,
            ];
        }

        $eligible = array_filter($evaluated, fn ($c) => $c['eligible'] && $c['cost_metric'] !== null);

        usort($eligible, fn ($a, $b) => $a['cost_metric'] <=> $b['cost_metric']);

        return [
            'recommended' => $eligible[0]['model'] ?? null,
            'min_quality' => $minQuality,
            'candidates' => $evaluated,
        ];
    }
}
