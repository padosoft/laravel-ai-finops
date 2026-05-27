<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\LaravelAiFinOps\Models\PriceWatchSubscription;
use Padosoft\LaravelAiFinOps\PriceWatch\PriceWatchService;

class PriceWatchController
{
    public function subscriptions(): JsonResponse
    {
        return response()->json(['data' => PriceWatchSubscription::query()->orderBy('model')->get()]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model' => ['required', 'string', 'max:128'],
            'provider' => ['nullable', 'string', 'max:64'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $sub = PriceWatchSubscription::updateOrCreate(
            ['model' => $data['model'], 'provider' => $data['provider'] ?? null],
            ['enabled' => $data['enabled'] ?? true],
        );

        return response()->json($sub, $sub->wasRecentlyCreated ? 201 : 200);
    }

    public function unsubscribe(string $id): JsonResponse
    {
        PriceWatchSubscription::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function changes(PriceWatchService $service): JsonResponse
    {
        return response()->json(['data' => $service->changes()]);
    }

    public function capture(PriceWatchService $service): JsonResponse
    {
        return response()->json(['captured' => $service->capture()]);
    }
}
