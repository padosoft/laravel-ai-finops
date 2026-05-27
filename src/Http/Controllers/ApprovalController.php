<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\LaravelAiFinOps\Models\SpendApproval;

class ApprovalController
{
    public function index(Request $request): JsonResponse
    {
        $query = SpendApproval::query()->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->paginate(min(200, max(1, (int) $request->integer('per_page', 25)))));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(SpendApproval::query()->findOrFail($id));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'approved');
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'rejected');
    }

    private function decide(Request $request, string $id, string $status): JsonResponse
    {
        $data = $request->validate([
            'decided_by' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $approval = SpendApproval::query()->findOrFail($id);

        if ($approval->status !== 'pending') {
            return response()->json(['message' => "approval already {$approval->status}"], 409);
        }

        $approval->update([
            'status' => $status,
            'decided_by' => $data['decided_by'] ?? null,
            'reason' => $data['reason'] ?? $approval->reason,
            'decided_at' => now(),
        ]);

        return response()->json($approval);
    }
}
