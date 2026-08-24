<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;

class DashboardController
{
    public function kpis(): JsonResponse
    {
        $base = UsageRecord::query();

        $today = (clone $base)->whereDate('created_at', today())->sum('cost_total');
        $month = (clone $base)->where('created_at', '>=', now()->startOfMonth())->sum('cost_total');
        $calls = (clone $base)->count();
        $cost = (float) (clone $base)->sum('cost_total');

        return response()->json([
            // No FX conversion in M1: report in the stored base currency, not display.
            'currency' => config('ai-finops.currency.base', 'USD'),
            'cost_today' => round((float) $today, 6),
            'cost_month_to_date' => round((float) $month, 6),
            'cost_total' => round($cost, 6),
            'calls_total' => $calls,
            'tokens_total' => (int) (clone $base)->sum(DB::raw('tokens_input + tokens_output')),
            'avg_cost_per_call' => $calls > 0 ? round($cost / $calls, 8) : 0.0,
            'models_count' => (clone $base)->distinct()->count('model'),
        ]);
    }

    public function spendTrend(Request $request): JsonResponse
    {
        $request->validate(['from' => 'nullable|date']);
        $from = $request->filled('from') ? $request->date('from') : now()->subDays(30);

        $rows = UsageRecord::query()
            ->where('created_at', '>=', $from)
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get([
                DB::raw('DATE(created_at) as day'),
                DB::raw('SUM(cost_total) as cost'),
                DB::raw('COUNT(*) as calls'),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function topModels(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->topBy('model', $request)]);
    }

    public function topTenants(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->topBy('tenant_id', $request)]);
    }

    /** Delegated-agent spend pivot: what each IAM delegation grant consumed (per user↔agent pair). */
    public function topDelegations(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->topBy('delegation_grant_id', $request)]);
    }

    /** @return Collection<int,object> */
    private function topBy(string $column, Request $request): Collection
    {
        $limit = min(50, max(1, (int) $request->integer('limit', 10)));

        return UsageRecord::query()
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('cost')
            ->limit($limit)
            ->get([
                $column,
                DB::raw('SUM(cost_total) as cost'),
                DB::raw('COUNT(*) as calls'),
                DB::raw('SUM(tokens_input + tokens_output) as tokens'),
            ]);
    }
}
