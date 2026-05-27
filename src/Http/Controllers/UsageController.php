<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\LaravelAiFinOps\Contracts\QualityScoreProvider;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;

class UsageController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(200, max(1, (int) $request->integer('per_page', 25)));

        $query = UsageRecord::query()->latest('id');

        foreach (['provider', 'model', 'status', 'tenant_id', 'cost_center', 'purpose_tag'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, (string) $request->input($field));
            }
        }

        if ($request->filled('trace_id')) {
            $query->where('trace_id', (string) $request->input('trace_id'));
        }

        if ($request->filled('from')) {
            $request->validate(['from' => 'date']);
            $query->where('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $request->validate(['to' => 'date']);
            $query->where('created_at', '<=', $request->date('to'));
        }

        return response()->json($query->paginate($perPage));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(UsageRecord::query()->findOrFail($id));
    }

    /**
     * All ledger rows for a trace, with totals — the basis for the per-step
     * cost flame-graph in the admin.
     */
    public function trace(string $traceId, QualityScoreProvider $quality): JsonResponse
    {
        $rows = UsageRecord::query()
            ->where('trace_id', $traceId)
            ->orderBy('id')
            ->get();

        // Correlated cost + quality view: a quality score per distinct model in the
        // trace (from the eval-harness seam; empty when not wired).
        $models = $rows->pluck('model')->unique()->values();
        $qualityByModel = $models->mapWithKeys(fn (string $m) => [$m => $quality->scoreFor($m)])->all();

        return response()->json([
            'trace_id' => $traceId,
            'spans' => $rows,
            'totals' => [
                'calls' => $rows->count(),
                'cost_total' => round((float) $rows->sum('cost_total'), 8),
                'tokens_input' => (int) $rows->sum('tokens_input'),
                'tokens_output' => (int) $rows->sum('tokens_output'),
                'currency' => $rows->first()->currency ?? config('ai-finops.currency.base', 'USD'),
            ],
            'quality' => $qualityByModel,
        ]);
    }
}
