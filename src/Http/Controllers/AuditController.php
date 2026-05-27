<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\LaravelAiFinOps\Models\AuditEntry;

class AuditController
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditEntry::query()->latest('id');

        foreach (['event', 'subject_type', 'subject_id', 'actor'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, (string) $request->input($field));
            }
        }

        return response()->json($query->paginate(min(200, max(1, (int) $request->integer('per_page', 50)))));
    }
}
