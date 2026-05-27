<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Padosoft\LaravelAiFinOps\Enums\BudgetScope;
use Padosoft\LaravelAiFinOps\Models\CreditPool;
use Padosoft\LaravelAiFinOps\Models\CreditTransaction;

class CreditController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => CreditPool::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(CreditPool::create($this->validatePool($request)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $pool = CreditPool::query()->findOrFail($id);
        $pool->update($this->validatePool($request));

        return response()->json($pool);
    }

    public function destroy(string $id): JsonResponse
    {
        CreditPool::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function topup(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $pool = DB::connection(config('ai-finops.storage.connection'))->transaction(function () use ($id, $data) {
            $pool = CreditPool::query()->lockForUpdate()->findOrFail($id);
            $pool->increment('balance', (float) $data['amount']);

            CreditTransaction::create([
                'pool_id' => $pool->id,
                'amount' => (float) $data['amount'],
                'type' => 'topup',
                'reason' => $data['reason'] ?? null,
                'created_at' => now(),
            ]);

            return $pool->refresh();
        });

        return response()->json($pool);
    }

    public function ledger(string $id): JsonResponse
    {
        $pool = CreditPool::query()->findOrFail($id);

        return response()->json([
            'pool_id' => (int) $pool->id,
            'balance' => $pool->balance,
            'currency' => $pool->currency,
            'transactions' => CreditTransaction::query()->where('pool_id', $pool->id)->latest('id')->get(),
        ]);
    }

    /** @return array<string,mixed> */
    private function validatePool(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scope_type' => ['required', Rule::enum(BudgetScope::class)],
            'scope_id' => ['nullable', 'string', 'max:128'],
            'balance' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
    }
}
