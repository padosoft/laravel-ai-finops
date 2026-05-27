<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\PriceWatch;

use Padosoft\LaravelAiFinOps\Models\PriceSnapshot;
use Padosoft\LaravelAiFinOps\Models\PriceWatchSubscription;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;

/**
 * Snapshots prices for subscribed models and reports changes between the two most
 * recent snapshots — so provider list-price moves are detected (reusing the
 * price-intelligence "watch the market" DNA, self-contained over the pricing registry).
 */
class PriceWatchService
{
    public function __construct(private readonly PricingRegistry $pricing) {}

    /** Snapshot current prices for every enabled subscription. Returns count captured. */
    public function capture(): int
    {
        $count = 0;

        foreach (PriceWatchSubscription::query()->where('enabled', true)->get() as $sub) {
            $price = $this->pricing->priceFor($sub->model, $sub->provider);
            if ($price === null) {
                continue;
            }

            PriceSnapshot::create([
                'model' => $sub->model,
                'provider' => $sub->provider,
                'input_cost_per_token' => $price->inputPerToken,
                'output_cost_per_token' => $price->outputPerToken,
                'source' => $price->source,
                'captured_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Changes between the two latest snapshots per subscription.
     *
     * @return array<int,array<string,mixed>>
     */
    public function changes(): array
    {
        $changes = [];

        foreach (PriceWatchSubscription::query()->get() as $sub) {
            $snaps = PriceSnapshot::query()
                ->where('model', $sub->model)
                ->when(
                    $sub->provider !== null,
                    fn ($q) => $q->where('provider', $sub->provider),
                    fn ($q) => $q->whereNull('provider'),
                )
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->limit(2)
                ->get();

            if ($snaps->count() < 2) {
                continue;
            }

            [$new, $old] = [$snaps[0], $snaps[1]];

            if ($new->input_cost_per_token === $old->input_cost_per_token
                && $new->output_cost_per_token === $old->output_cost_per_token) {
                continue;
            }

            $changes[] = [
                'model' => $sub->model,
                'provider' => $sub->provider,
                'old_input' => $old->input_cost_per_token,
                'new_input' => $new->input_cost_per_token,
                'old_output' => $old->output_cost_per_token,
                'new_output' => $new->output_cost_per_token,
                'input_change_pct' => $this->pct($old->input_cost_per_token, $new->input_cost_per_token),
                'output_change_pct' => $this->pct($old->output_cost_per_token, $new->output_cost_per_token),
                'captured_at' => $new->captured_at?->toIso8601String(),
            ];
        }

        return $changes;
    }

    private function pct(float $old, float $new): ?float
    {
        if ($old == 0.0) {
            return null;
        }

        return round((($new - $old) / $old) * 100, 4);
    }
}
