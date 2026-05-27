<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Models\WhatIfScenario;
use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;

class WhatIfController
{
    /**
     * Replay historical traffic for `from_model` and re-price it as if it had run
     * on `to_model`, to project savings. No provider calls are made.
     */
    public function simulate(Request $request, PricingRegistry $pricing, CostCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'from_model' => ['required', 'string', 'max:128'],
            'to_model' => ['required', 'string', 'max:128'],
            'provider' => ['nullable', 'string', 'max:64'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        return response()->json($this->compute($data, $pricing, $calculator));
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => WhatIfScenario::query()->latest('id')->get()]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(WhatIfScenario::query()->findOrFail($id));
    }

    public function store(Request $request, PricingRegistry $pricing, CostCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'from_model' => ['required', 'string', 'max:128'],
            'to_model' => ['required', 'string', 'max:128'],
            'provider' => ['nullable', 'string', 'max:64'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $result = $this->compute($data, $pricing, $calculator);

        $scenario = WhatIfScenario::create([
            'name' => $data['name'],
            'payload' => collect($data)->except('name')->all(),
            'result' => $result,
        ]);

        return response()->json($scenario, 201);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function compute(array $data, PricingRegistry $pricing, CostCalculator $calculator): array
    {
        $since = now()->subDays((int) ($data['days'] ?? 30));
        $targetPrice = $pricing->priceFor($data['to_model'], $data['provider'] ?? null);
        $currency = (string) config('ai-finops.currency.base', 'USD');

        $rows = UsageRecord::query()
            ->where('model', $data['from_model'])
            ->where('created_at', '>=', $since)
            ->get(['tokens_input', 'tokens_output', 'tokens_cached', 'tokens_reasoning', 'cost_total']);

        $current = 0.0;
        $projected = 0.0;

        foreach ($rows as $row) {
            $current += (float) $row->cost_total;
            $projected += $calculator->cost(new TokenUsage(
                input: (int) $row->tokens_input,
                output: (int) $row->tokens_output,
                cached: (int) $row->tokens_cached,
                reasoning: (int) $row->tokens_reasoning,
            ), $targetPrice, $currency)->total;
        }

        return [
            'from_model' => $data['from_model'],
            'to_model' => $data['to_model'],
            'calls' => $rows->count(),
            'current_cost' => round($current, 6),
            'projected_cost' => round($projected, 6),
            'savings' => round($current - $projected, 6),
            'priced' => $targetPrice !== null,
            'currency' => $currency,
        ];
    }
}
