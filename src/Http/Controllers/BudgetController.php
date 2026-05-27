<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Padosoft\LaravelAiFinOps\Enums\BudgetPeriod;
use Padosoft\LaravelAiFinOps\Enums\BudgetScope;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;

class BudgetController
{
    public function index(): JsonResponse
    {
        $data = Budget::query()->orderBy('name')->get()->map(
            fn (Budget $b) => $b->status()->toArray($b->soft_limit_pct) + [
                'scope_type' => $b->scope_type,
                'scope_id' => $b->scope_id,
                'parent_id' => $b->parent_id,
                'period' => $b->period,
            ],
        );

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $budget = Budget::create($this->validateBudget($request));

        return response()->json($budget, 201);
    }

    public function show(string $id): JsonResponse
    {
        $budget = Budget::query()->findOrFail($id);

        return response()->json($budget->toArray() + [
            'status' => $budget->status()->toArray($budget->soft_limit_pct),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $budget = Budget::query()->findOrFail($id);
        $budget->update($this->validateBudget($request, (int) $id));

        return response()->json($budget);
    }

    public function destroy(string $id): JsonResponse
    {
        $budget = Budget::query()->findOrFail($id);

        // Re-parent children to the deleted node's parent so the tree never orphans
        // budgets that are still enforced/listed.
        Budget::query()->where('parent_id', $budget->id)->update(['parent_id' => $budget->parent_id]);

        $budget->delete();

        return response()->json(['deleted' => true]);
    }

    public function tree(): JsonResponse
    {
        $all = Budget::query()->orderBy('name')->get();

        $build = function (?int $parentId) use (&$build, $all): array {
            return $all->where('parent_id', $parentId)->map(fn (Budget $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'scope_type' => $b->scope_type,
                'scope_id' => $b->scope_id,
                'status' => $b->status()->toArray($b->soft_limit_pct),
                'children' => $build($b->id),
            ])->values()->all();
        };

        return response()->json(['data' => $build(null)]);
    }

    public function burndown(string $id): JsonResponse
    {
        $budget = Budget::query()->findOrFail($id);
        $start = $budget->currentPeriodStart();

        $query = UsageRecord::query()->where('created_at', '>=', $start);
        if (($column = $budget->scope()->ledgerColumn()) !== null) {
            $query->where($column, (string) $budget->scope_id);
        }

        $daily = $query->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get([DB::raw('DATE(created_at) as day'), DB::raw('SUM(cost_total) as cost')]);

        $running = 0.0;
        $series = $daily->map(function ($row) use (&$running) {
            $running += (float) $row->cost;

            return ['day' => $row->day, 'cost' => round((float) $row->cost, 8), 'cumulative' => round($running, 8)];
        });

        return response()->json([
            'budget_id' => $budget->id,
            'limit' => (float) $budget->limit_amount,
            'currency' => $budget->currency,
            'period_start' => $start,
            'series' => $series,
        ]);
    }

    /** @return array<string,mixed> */
    private function validateBudget(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', Rule::exists($this->existsTarget(), 'id')->where(
                fn ($q) => $ignoreId ? $q->where('id', '!=', $ignoreId) : $q,
            )],
            'scope_type' => ['required', Rule::enum(BudgetScope::class)],
            'scope_id' => ['nullable', 'string', 'max:128', Rule::requiredIf(
                fn () => $request->input('scope_type') !== BudgetScope::Global->value,
            )],
            'limit_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'period' => ['required', Rule::enum(BudgetPeriod::class)],
            'rolling_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'soft_limit_pct' => ['nullable', 'integer', 'min:1', 'max:100'],
            'hard' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
    }

    private function table(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'budgets';
    }

    /** Qualify the exists() target with the configured connection ("connection.table"). */
    private function existsTarget(): string
    {
        $connection = config('ai-finops.storage.connection');

        return ($connection ? $connection.'.' : '').$this->table();
    }
}
