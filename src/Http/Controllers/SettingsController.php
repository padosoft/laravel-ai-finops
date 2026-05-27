<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Models\KillSwitch;
use Padosoft\LaravelAiFinOps\Policies\PolicyEngine;
use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;

class SettingsController
{
    /** Effective runtime settings snapshot (read-only in M2; mutation arrives in M3). */
    public function index(): JsonResponse
    {
        return response()->json([
            'enabled' => (bool) config('ai-finops.enabled'),
            'metering' => (bool) config('ai-finops.metering'),
            'enforcement' => (bool) config('ai-finops.enforcement'),
            'kill_switch' => (bool) config('ai-finops.kill_switch'),
            'currency' => [
                'base' => config('ai-finops.currency.base'),
                'display' => config('ai-finops.currency.display'),
            ],
            'retention_days' => (int) config('ai-finops.storage.retention_days'),
            'block_status' => (int) config('ai-finops.block_status'),
        ]);
    }

    public function killSwitches(): JsonResponse
    {
        return response()->json([
            'global_config' => (bool) config('ai-finops.kill_switch'),
            'data' => KillSwitch::query()->orderBy('scope_type')->get(),
        ]);
    }

    public function setKillSwitch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type' => ['required', Rule::in(['global', 'provider', 'tenant'])],
            // scope_id is required for scoped types; must be absent for global.
            'scope_id' => [
                Rule::requiredIf(fn () => in_array($request->input('scope_type'), ['provider', 'tenant'], true)),
                'nullable', 'string', 'max:128',
            ],
            'active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        // Normalise scope_id to '' (not NULL) so the composite unique index dedupes
        // the global/unscoped row reliably across MySQL/Postgres.
        $data['scope_id'] = $data['scope_type'] === 'global' ? '' : ($data['scope_id'] ?? '');

        $switch = KillSwitch::updateOrCreate(
            ['scope_type' => $data['scope_type'], 'scope_id' => $data['scope_id']],
            ['active' => $data['active'], 'reason' => $data['reason'] ?? null],
        );

        return response()->json($switch, $switch->wasRecentlyCreated ? 201 : 200);
    }

    /** Estimate cost + policy decision for a hypothetical call (no provider charge). */
    public function estimate(Request $request, PricingRegistry $pricing, CostCalculator $calculator, PolicyEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:64'],
            'model' => ['required', 'string', 'max:128'],
            'tokens_input' => ['required', 'integer', 'min:0'],
            'tokens_output' => ['sometimes', 'integer', 'min:0'],
            'tokens_cached' => ['sometimes', 'integer', 'min:0'],
            'tenant_id' => ['nullable', 'string', 'max:64'],
        ]);

        $tokens = new TokenUsage(
            input: (int) $data['tokens_input'],
            output: (int) ($data['tokens_output'] ?? 0),
            cached: (int) ($data['tokens_cached'] ?? 0),
        );

        $price = $pricing->priceFor($data['model'], $data['provider']);
        $cost = $calculator->cost($tokens, $price, (string) config('ai-finops.currency.base', 'USD'));

        $decision = $engine->evaluate(new AiCallEnvelope(
            traceId: 'estimate',
            provider: $data['provider'],
            model: $data['model'],
            tokens: $tokens,
            cost: $cost,
            tenantId: $data['tenant_id'] ?? null,
        ));

        return response()->json([
            'cost' => $cost->toArray(),
            'price_source' => $price?->source,
            'decision' => $decision->toArray(),
        ]);
    }
}
