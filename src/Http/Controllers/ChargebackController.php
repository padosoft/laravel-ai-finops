<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Padosoft\LaravelAiFinOps\Models\CostCenter;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;

class ChargebackController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => CostCenter::query()->orderBy('code')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(CostCenter::create($this->validateCenter($request)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $center = CostCenter::query()->findOrFail($id);
        $center->update($this->validateCenter($request, (int) $id));

        return response()->json($center);
    }

    public function destroy(string $id): JsonResponse
    {
        CostCenter::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Spend allocated per cost center over a period (showback/chargeback). Ledger
     * rows with no cost_center are reported under the "unallocated" bucket.
     */
    public function report(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = UsageRecord::query();
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to'));
        }

        $rows = (clone $query)
            ->groupBy('cost_center')
            ->orderByDesc('cost')
            ->get(['cost_center', DB::raw('SUM(cost_total) as cost'), DB::raw('COUNT(*) as calls')]);

        $names = CostCenter::query()->pluck('name', 'code');

        $data = $rows->map(fn ($r) => [
            'cost_center' => $r->cost_center ?: 'unallocated',
            'name' => $r->cost_center ? ($names[$r->cost_center] ?? null) : 'Unallocated',
            'cost' => round((float) $r->cost, 6),
            'calls' => (int) $r->calls,
        ]);

        return response()->json([
            // Totals are summed in the stored/base currency; no FX is applied here,
            // so report the base currency to avoid mislabeling figures.
            'currency' => config('ai-finops.currency.base', 'USD'),
            'total' => round((float) (clone $query)->sum('cost_total'), 6),
            'data' => $data->values(),
        ]);
    }

    /** @return array<string,mixed> */
    private function validateCenter(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:128', Rule::unique($this->table(), 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:255'],
            'owner' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
    }

    private function table(): string
    {
        $connection = config('ai-finops.storage.connection');

        return ($connection ? $connection.'.' : '').config('ai-finops.storage.table_prefix', 'ai_finops_').'cost_centers';
    }
}
