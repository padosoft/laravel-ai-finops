<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Padosoft\LaravelAiFinOps\Contracts\TokenEstimator;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Models\KillSwitch;
use Padosoft\LaravelAiFinOps\Policies\PolicyEngine;
use Padosoft\LaravelAiFinOps\Pricing\Cost\TiktokenTokenEstimator;
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
            'pricing' => [
                'sources' => config('ai-finops.pricing.sources'),
                'actual_cost_enabled' => (bool) config('ai-finops.pricing.actual_cost.enabled'),
                'has_openrouter_key' => filled(config('ai-finops.pricing.openrouter.key')),
                // Which token estimator is active for the cost cascade case (c).
                'token_estimator' => app(TokenEstimator::class) instanceof TiktokenTokenEstimator ? 'tiktoken' : 'heuristic',
            ],
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

    /**
     * Estimate cost + policy decision for a hypothetical call (no provider charge).
     * Accepts explicit token counts OR a `prompt`/`messages` to estimate tokens from
     * text (cascade case c) — useful to simulate a call's cost before sending it.
     */
    public function estimate(Request $request, PricingRegistry $pricing, CostCalculator $calculator, PolicyEngine $engine, TokenEstimator $estimator): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:64'],
            'model' => ['required', 'string', 'max:128'],
            // At least one of tokens_input / prompt / messages must be supplied.
            // tokens_input is required when neither prompt nor messages are present.
            'tokens_input' => ['required_without_all:prompt,messages', 'sometimes', 'integer', 'min:0'],
            'tokens_output' => ['sometimes', 'integer', 'min:0'],
            'tokens_cached' => ['sometimes', 'integer', 'min:0'],
            'prompt' => ['sometimes', 'string'],
            'messages' => ['sometimes', 'array'],
            'tenant_id' => ['nullable', 'string', 'max:64'],
        ]);

        $hasExplicitTokens = $request->filled('tokens_input');
        $estimated = ! $hasExplicitTokens && ($request->filled('prompt') || $request->filled('messages'));

        if ($estimated) {
            $promptInput = $data['prompt'] ?? $data['messages'];
            $input = $estimator->estimate($promptInput, $data['model'])->input;
            $output = (int) round($input * (float) config('ai-finops.pricing.token_estimation.expected_output_ratio', 1.0));
            $tokens = new TokenUsage(input: $input, output: $output);
        } else {
            $tokens = new TokenUsage(
                input: (int) ($data['tokens_input'] ?? 0),
                output: (int) ($data['tokens_output'] ?? 0),
                cached: (int) ($data['tokens_cached'] ?? 0),
            );
        }

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
            'tokens' => $tokens->toArray(),
            'method' => $estimated ? 'estimated' : 'computed',
            'tokens_estimated' => $estimated,
            'price_source' => $price?->source,
            'decision' => $decision->toArray(),
        ]);
    }
}
