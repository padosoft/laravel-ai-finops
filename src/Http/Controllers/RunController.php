<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Padosoft\LaravelAiFinOps\Enums\RunEventKind;
use Padosoft\LaravelAiFinOps\Enums\RunEventStatus;
use Padosoft\LaravelAiFinOps\Models\RunEvent;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;

/**
 * Read-only view over the shape of agent runs: what each step cost, how long
 * each tool took, what failed, and — through the parent invocation recorded on
 * every event — which run called which.
 */
class RunController
{
    /**
     * Recent runs, one row per invocation, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(200, max(1, (int) $request->integer('per_page', 25)));

        $runs = RunEvent::query()
            ->selectRaw('invocation_id')
            ->selectRaw('MIN(agent) as agent')
            ->selectRaw('MIN(provider) as provider')
            ->selectRaw('MIN(model) as model')
            ->selectRaw('MIN(parent_invocation_id) as parent_invocation_id')
            ->selectRaw('MIN(tenant_id) as tenant_id')
            ->selectRaw('MIN(delegation_grant_id) as delegation_grant_id')
            ->selectRaw('SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) as steps', [RunEventKind::Step->value])
            ->selectRaw('SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) as tools', [RunEventKind::Tool->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failures', [RunEventStatus::Failed->value])
            ->selectRaw('SUM(cost_total) as cost_total')
            ->selectRaw('SUM(duration_ms) as duration_ms')
            ->selectRaw('MAX(created_at) as ended_at')
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->string('tenant_id')))
            ->when($request->boolean('failed_only'), fn ($q) => $q->failed())
            ->groupBy('invocation_id')
            ->orderByDesc('ended_at')
            ->paginate($perPage);

        return response()->json($runs);
    }

    /**
     * One run in full: its steps in order, the tools each step called, the ledger
     * rows billed against it, and the runs it delegated to.
     */
    public function show(string $invocationId): JsonResponse
    {
        $events = RunEvent::query()
            ->where('invocation_id', $invocationId)
            ->orderBy('id')
            ->get();

        $steps = $events->where('kind', RunEventKind::Step->value)->values();
        $tools = $events->where('kind', RunEventKind::Tool->value)->values();

        return response()->json([
            'invocation_id' => $invocationId,
            'parent' => $this->parentOf($events),
            'steps' => $steps,
            // Tools are listed alongside rather than nested under a step: laravel/ai
            // reports a tool invocation against the run, not against the step that
            // asked for it, so nesting them would be a guess presented as a fact.
            'tools' => $tools,
            'children' => $this->children($invocationId),
            'ledger' => UsageRecord::query()
                ->where('invocation_id', $invocationId)
                ->orderBy('id')
                ->get(),
            'totals' => [
                'steps' => $steps->count(),
                'tools' => $tools->count(),
                'failures' => $events->where('status', RunEventStatus::Failed->value)->count(),
                'tokens_input' => (int) $events->sum('tokens_input'),
                'tokens_output' => (int) $events->sum('tokens_output'),
                'cost_total' => round((float) $events->sum('cost_total'), 8),
                'duration_ms' => (int) $events->sum('duration_ms'),
                'currency' => $events->first()->currency ?? config('ai-finops.currency.base', 'USD'),
            ],
        ]);
    }

    /**
     * The runs this one delegated to, each with the tool invocation it was called
     * from — the edge that makes the chain a tree rather than a list.
     */
    private function children(string $invocationId): array
    {
        return RunEvent::query()
            ->where('parent_invocation_id', $invocationId)
            ->selectRaw('invocation_id')
            ->selectRaw('MIN(agent) as agent')
            ->selectRaw('MIN(parent_tool_invocation_id) as called_from_tool_invocation_id')
            ->selectRaw('SUM(cost_total) as cost_total')
            ->selectRaw('SUM(duration_ms) as duration_ms')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failures', [RunEventStatus::Failed->value])
            ->groupBy('invocation_id')
            ->get()
            ->all();
    }

    /**
     * @param  Collection<int, RunEvent>  $events
     * @return array<string,mixed>|null
     */
    private function parentOf(Collection $events): ?array
    {
        $parent = $events->firstWhere(fn (RunEvent $e): bool => $e->parent_invocation_id !== null);

        return $parent === null ? null : [
            'invocation_id' => $parent->parent_invocation_id,
            'called_from_tool_invocation_id' => $parent->parent_tool_invocation_id,
        ];
    }
}
