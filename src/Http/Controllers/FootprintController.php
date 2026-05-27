<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;

class FootprintController
{
    public function summary(Request $request): JsonResponse
    {
        $query = UsageRecord::query();
        if ($request->filled('from')) {
            $request->validate(['from' => 'date']);
            $query->where('created_at', '>=', $request->date('from'));
        }

        $tokens = (int) $query->sum(DB::raw('tokens_input + tokens_output'));
        [$energy, $co2] = $this->estimate($tokens);

        return response()->json([
            'tokens' => $tokens,
            'energy_kwh' => $energy,
            'co2_grams' => $co2,
            'co2_kg' => round($co2 / 1000, 4),
        ]);
    }

    public function trend(Request $request): JsonResponse
    {
        $from = $request->filled('from') ? $request->date('from') : now()->subDays(30);

        $rows = UsageRecord::query()
            ->where('created_at', '>=', $from)
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get([DB::raw('DATE(created_at) as day'), DB::raw('SUM(tokens_input + tokens_output) as tokens')]);

        $data = $rows->map(function ($r) {
            [$energy, $co2] = $this->estimate((int) $r->tokens);

            return ['day' => $r->day, 'tokens' => (int) $r->tokens, 'energy_kwh' => $energy, 'co2_grams' => $co2];
        });

        return response()->json(['data' => $data]);
    }

    /** @return array{0: float, 1: float} [energy_kwh, co2_grams] */
    private function estimate(int $tokens): array
    {
        $kwhPer1k = (float) config('ai-finops.footprint.kwh_per_1k_tokens', 0.0005);
        $gco2PerKwh = (float) config('ai-finops.footprint.grid_gco2_per_kwh', 400);

        $energy = $tokens / 1000 * $kwhPer1k;

        return [round($energy, 6), round($energy * $gco2PerKwh, 4)];
    }
}
