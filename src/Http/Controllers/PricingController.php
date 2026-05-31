<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\Pricing\PricingSourceManager;

class PricingController
{
    private const SYNC_AT_KEY = 'ai-finops:pricing:synced_at';

    public function models(Request $request, PricingSourceManager $sources): JsonResponse
    {
        $search = strtolower((string) $request->string('search'));
        $sourceFilter = (string) $request->string('source');
        $limit = min(500, max(1, (int) $request->integer('limit', 100)));

        $models = [];
        foreach ($sources->merged() as $model => $attr) {
            if ($search !== '' && ! str_contains(strtolower((string) $model), $search)) {
                continue;
            }
            if ($sourceFilter !== '' && ($attr['_source'] ?? null) !== $sourceFilter) {
                continue;
            }
            $models[] = [
                'model' => $model,
                'provider' => $attr['litellm_provider'] ?? null,
                'input_cost_per_token' => $attr['input_cost_per_token'] ?? null,
                'output_cost_per_token' => $attr['output_cost_per_token'] ?? null,
                'source' => $attr['_source'] ?? null,
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

    public function syncStatus(PricingSourceManager $sources): JsonResponse
    {
        $list = [];
        $total = 0;

        foreach ($sources->sources() as $source) {
            $count = count($source->all());
            $total += $count;
            $list[] = [
                'name' => $source->name(),
                'synced_at' => $source->syncedAt()?->format(\DateTimeInterface::ATOM),
                'models' => $count,
            ];
        }

        return response()->json([
            'synced_at' => Cache::get(self::SYNC_AT_KEY),
            'models' => $total,
            'sources' => $list,
            // Secret presence only — the key itself is never serialized.
            'has_openrouter_key' => filled(config('ai-finops.pricing.openrouter.key')),
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
            // `sometimes` (not nullable): the column is non-null with a default, so
            // omit it when absent rather than persisting NULL.
            'currency' => ['sometimes', 'string', 'size:3'],
            // Operators can enter feed-less prices per-million (e.g. regolo, EUR).
            'unit' => ['sometimes', 'in:per_token,per_million'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
    }
}
