<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Enums\BudgetScope;
use Padosoft\LaravelAiFinOps\Enums\PolicyAction;
use Padosoft\LaravelAiFinOps\Models\Policy;

class PolicyController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Policy::query()->orderBy('priority')->orderBy('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Policy::create($this->validatePolicy($request)), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Policy::query()->findOrFail($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $policy = Policy::query()->findOrFail($id);
        $policy->update($this->validatePolicy($request));

        return response()->json($policy);
    }

    public function destroy(string $id): JsonResponse
    {
        Policy::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function validatePayload(Request $request): JsonResponse
    {
        $this->validatePolicy($request);

        return response()->json(['valid' => true]);
    }

    /** Check whether a policy matches a sample call and what action it would take. */
    public function simulate(Request $request, string $id): JsonResponse
    {
        $policy = Policy::query()->findOrFail($id);
        $envelope = $this->sampleEnvelope($request);

        return response()->json([
            'matches' => $policy->matches($envelope),
            'action' => $policy->action()->value,
            'action_param' => $policy->action_param,
        ]);
    }

    private function sampleEnvelope(Request $request): AiCallEnvelope
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:64'],
            'model' => ['required', 'string', 'max:128'],
            'tenant_id' => ['nullable', 'string', 'max:64'],
            'cost_center' => ['nullable', 'string', 'max:128'],
            'estimated_cost' => ['sometimes', 'numeric', 'min:0'],
        ]);

        return new AiCallEnvelope(
            traceId: 'simulate',
            provider: $data['provider'],
            model: $data['model'],
            cost: new CostBreakdown(total: (float) ($data['estimated_cost'] ?? 0.0), currency: 'USD'),
            tenantId: $data['tenant_id'] ?? null,
            costCenter: $data['cost_center'] ?? null,
        );
    }

    /** @return array<string,mixed> */
    private function validatePolicy(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scope_type' => ['required', Rule::enum(BudgetScope::class)],
            'scope_id' => ['nullable', 'string', 'max:128', Rule::requiredIf(
                fn () => $request->input('scope_type') !== BudgetScope::Global->value,
            )],
            'min_cost' => ['nullable', 'numeric', 'min:0'],
            'model_match' => ['nullable', 'string', 'max:128'],
            'action' => ['required', Rule::enum(PolicyAction::class)],
            'action_param' => ['nullable', 'string', 'max:128', Rule::requiredIf(
                fn () => $request->input('action') === PolicyAction::Downgrade->value,
            )],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
    }
}
