<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Padosoft\LaravelAiFinOps\Contracts\QualityScoreProvider;
use Padosoft\LaravelAiFinOps\Enums\BudgetScope;
use Padosoft\LaravelAiFinOps\Models\RoutingRule;
use Padosoft\LaravelAiFinOps\Routing\RoutingEngine;

class RoutingController
{
    public function rules(): JsonResponse
    {
        return response()->json(['data' => RoutingRule::query()->orderBy('name')->get()]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        return response()->json(RoutingRule::create($this->validateRule($request)), 201);
    }

    public function updateRule(Request $request, string $id): JsonResponse
    {
        $rule = RoutingRule::query()->findOrFail($id);
        $rule->update($this->validateRule($request));

        return response()->json($rule);
    }

    public function destroyRule(string $id): JsonResponse
    {
        RoutingRule::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function qualityScores(QualityScoreProvider $quality): JsonResponse
    {
        return response()->json([
            'enabled' => (bool) config('ai-finops.integrations.eval_harness.enabled', false),
            'scores' => $quality->all(),
        ]);
    }

    public function simulate(Request $request, RoutingEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'candidates' => ['required', 'array', 'min:1'],
            'candidates.*' => ['string', 'max:128'],
            'min_quality' => ['nullable', 'numeric', 'between:0,1'],
            'provider' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json($engine->recommend(
            $data['candidates'],
            isset($data['min_quality']) ? (float) $data['min_quality'] : null,
            $data['provider'] ?? null,
        ));
    }

    /** @return array<string,mixed> */
    private function validateRule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scope_type' => ['required', Rule::enum(BudgetScope::class)],
            'scope_id' => ['nullable', 'string', 'max:128'],
            'candidates' => ['required', 'array', 'min:1'],
            'candidates.*' => ['string', 'max:128'],
            'min_quality' => ['nullable', 'numeric', 'between:0,1'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
    }
}
