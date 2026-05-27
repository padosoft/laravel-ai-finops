<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\LaravelAiFinOps\Contracts\CopilotProvider;
use Padosoft\LaravelAiFinOps\Models\CopilotQuery;

class CopilotController
{
    public function query(Request $request, CopilotProvider $copilot): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:2000']]);

        $result = $copilot->answer($data['question']);

        CopilotQuery::create([
            'question' => $data['question'],
            'answer' => $result['answer'] ?? null,
            'configured' => (bool) ($result['configured'] ?? false),
            'created_at' => now(),
        ]);

        return response()->json($result);
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json(
            CopilotQuery::query()->latest('id')->paginate(min(200, max(1, (int) $request->integer('per_page', 25)))),
        );
    }
}
