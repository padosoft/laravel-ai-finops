<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\LaravelAiFinOps\Forecasting\AnomalyDetector;
use Padosoft\LaravelAiFinOps\Forecasting\Forecaster;
use Padosoft\LaravelAiFinOps\Models\AnomalyAck;
use Padosoft\LaravelAiFinOps\Models\Budget;

class ForecastController
{
    public function index(Forecaster $forecaster): JsonResponse
    {
        return response()->json($forecaster->monthToDate());
    }

    public function budget(string $id, Forecaster $forecaster): JsonResponse
    {
        return response()->json($forecaster->forBudget(Budget::query()->findOrFail($id)));
    }

    public function anomalies(Request $request, AnomalyDetector $detector): JsonResponse
    {
        $days = min(120, max(3, (int) $request->integer('days', 30)));

        return response()->json(['data' => $detector->detect($days)]);
    }

    public function ackAnomaly(Request $request): JsonResponse
    {
        $data = $request->validate([
            'day' => ['required', 'date_format:Y-m-d'],
            'acked_by' => ['nullable', 'string', 'max:255'],
        ]);

        $ack = AnomalyAck::updateOrCreate(
            ['day' => $data['day']],
            ['acked_by' => $data['acked_by'] ?? null, 'created_at' => now()],
        );

        return response()->json($ack, $ack->wasRecentlyCreated ? 201 : 200);
    }
}
