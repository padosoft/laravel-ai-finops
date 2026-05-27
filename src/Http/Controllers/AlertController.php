<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Padosoft\LaravelAiFinOps\Events\AlertChannelTested;
use Padosoft\LaravelAiFinOps\Models\AlertChannel;
use Padosoft\LaravelAiFinOps\Models\AlertLogEntry;
use Padosoft\LaravelAiFinOps\Models\AlertRule;

class AlertController
{
    public function channels(): JsonResponse
    {
        return response()->json([
            'data' => AlertChannel::query()->orderBy('name')->get()->map->safeArray(),
        ]);
    }

    public function storeChannel(Request $request): JsonResponse
    {
        $channel = AlertChannel::create($this->validateChannel($request));

        return response()->json($channel->safeArray(), 201);
    }

    public function updateChannel(Request $request, string $id): JsonResponse
    {
        $channel = AlertChannel::query()->findOrFail($id);
        $channel->update($this->validateChannel($request));

        return response()->json($channel->safeArray());
    }

    public function destroyChannel(string $id): JsonResponse
    {
        AlertChannel::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    /** Fire a test event for a channel (host app delivers; no secret is returned). */
    public function testChannel(string $id): JsonResponse
    {
        $channel = AlertChannel::query()->findOrFail($id);

        event(new AlertChannelTested($channel));

        return response()->json(['tested' => true, 'channel_id' => (int) $channel->id]);
    }

    public function rules(): JsonResponse
    {
        return response()->json(['data' => AlertRule::query()->orderBy('budget_id')->get()]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        return response()->json(AlertRule::create($this->validateRule($request)), 201);
    }

    public function updateRule(Request $request, string $id): JsonResponse
    {
        $rule = AlertRule::query()->findOrFail($id);
        $rule->update($this->validateRule($request));

        return response()->json($rule);
    }

    public function destroyRule(string $id): JsonResponse
    {
        AlertRule::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function log(Request $request): JsonResponse
    {
        return response()->json(
            AlertLogEntry::query()->latest('id')->paginate(min(200, max(1, (int) $request->integer('per_page', 50)))),
        );
    }

    /** @return array<string,mixed> */
    private function validateChannel(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(['mail', 'slack', 'teams', 'webhook', 'sms'])],
            'name' => ['required', 'string', 'max:255'],
            'config' => ['nullable', 'array'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string,mixed> */
    private function validateRule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'budget_id' => ['required', 'integer', Rule::exists($this->budgetsTable(), 'id')],
            'threshold_pct' => ['required', 'integer', 'min:1', 'max:200'],
            'channel_id' => ['nullable', 'integer', Rule::exists($this->channelsTable(), 'id')],
            'enabled' => ['sometimes', 'boolean'],
        ]);
    }

    private function budgetsTable(): string
    {
        return $this->qualified('budgets');
    }

    private function channelsTable(): string
    {
        return $this->qualified('alert_channels');
    }

    private function qualified(string $suffix): string
    {
        $connection = config('ai-finops.storage.connection');

        return ($connection ? $connection.'.' : '').config('ai-finops.storage.table_prefix', 'ai_finops_').$suffix;
    }
}
