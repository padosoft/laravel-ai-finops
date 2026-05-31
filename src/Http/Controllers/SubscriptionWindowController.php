<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\LaravelAiFinOps\Models\SubscriptionWindow;

/**
 * CRUD for flat-rate subscription coverage windows (the "canoni" hand-entry mask).
 * Within an active window, metered calls to the provider cost 0; the operator
 * shortens `ends_at` when the provider signals the quota is exhausted.
 */
class SubscriptionWindowController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => SubscriptionWindow::query()->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $window = SubscriptionWindow::create($this->validatePayload($request));

        return response()->json($window, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $window = SubscriptionWindow::query()->findOrFail($id);
        $window->update($this->validatePayload($request));

        return response()->json($window);
    }

    public function destroy(string $id): JsonResponse
    {
        SubscriptionWindow::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string,mixed> */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'provider' => ['required', 'string', 'max:64'],
            'label' => ['required', 'string', 'max:128'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'enabled' => ['sometimes', 'boolean'],
            'tenant_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
    }
}
