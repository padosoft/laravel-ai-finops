<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;

class PricingController
{
    private const SYNC_AT_KEY = 'ai-finops:pricing:synced_at';

    public function models(Request $request, PricingSource $source): JsonResponse
    {
        $search = strtolower((string) $request->string('search'));
        $limit = min(500, max(1, (int) $request->integer('limit', 100)));

        $models = [];
        foreach ($source->all() as $model => $attr) {
            if ($search !== '' && ! str_contains(strtolower((string) $model), $search)) {
                continue;
            }
            $models[] = [
                'model' => $model,
                'provider' => $attr['litellm_provider'] ?? null,
                'input_cost_per_token' => $attr['input_cost_per_token'] ?? null,
                'output_cost_per_token' => $attr['output_cost_per_token'] ?? null,
            ];
            if (count($models) >= $limit) {
                break;
            }
        }

        return response()->json(['data' => $models, 'count' => count($models)]);
    }

    public function sync(PricingRegistry $registry): JsonResponse
    {
        $count = $registry->sync();
        $synced = $count > 0;

        // Only persist the timestamp when the sync actually retrieved data.
        $at = Cache::get(self::SYNC_AT_KEY);
        if ($synced) {
            $at = now()->toIso8601String();
            Cache::forever(self::SYNC_AT_KEY, $at);
        }

        return response()->json(['synced' => $synced, 'models' => $count, 'synced_at' => $at]);
    }

    public function syncStatus(PricingSource $source): JsonResponse
    {
        return response()->json([
            'synced_at' => Cache::get(self::SYNC_AT_KEY),
            'models' => count($source->all()),
        ]);
    }

    public function overrides(): JsonResponse
    {
        return response()->json(['data' => PricingOverride::query()->orderBy('model')->get()]);
    }

    public function storeOverride(Request $request): JsonResponse
    {
        $data = $this->validateOverride($request);

        $override = PricingOverride::updateOrCreate(
            ['model' => $data['model'], 'provider' => $data['provider'] ?? null],
            $data,
        );

        return response()->json($override, $override->wasRecentlyCreated ? 201 : 200);
    }

    public function updateOverride(Request $request, string $id): JsonResponse
    {
        $override = PricingOverride::query()->findOrFail($id);
        $override->update($this->validateOverride($request));

        return response()->json($override);
    }

    public function destroyOverride(string $id): JsonResponse
    {
        PricingOverride::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string,mixed> */
    private function validateOverride(Request $request): array
    {
        return $request->validate([
            'model' => ['required', 'string', 'max:128'],
            'provider' => ['nullable', 'string', 'max:64'],
            'input_cost_per_token' => ['required', 'numeric', 'min:0'],
            'output_cost_per_token' => ['required', 'numeric', 'min:0'],
            'cache_read_cost_per_token' => ['nullable', 'numeric', 'min:0'],
            'cache_write_cost_per_token' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
    }
}
