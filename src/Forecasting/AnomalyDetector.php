<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Forecasting;

use Illuminate\Support\Facades\DB;
use Padosoft\LaravelAiFinOps\Models\AnomalyAck;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;

/**
 * Flags daily spend spikes: a day is anomalous when its cost exceeds
 * mean + k·stddev of the trailing window (and a small absolute floor, so quiet
 * accounts don't page on rounding noise).
 */
class AnomalyDetector
{
    public function __construct(
        private readonly float $sigma = 2.5,
        private readonly float $floor = 0.01,
    ) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function detect(int $days = 30): array
    {
        $since = now()->subDays($days)->startOfDay();

        $rows = UsageRecord::query()
            ->where('created_at', '>=', $since)
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get([DB::raw('DATE(created_at) as day'), DB::raw('SUM(cost_total) as cost')]);

        if ($rows->count() < 3) {
            return [];
        }

        $values = $rows->map(fn ($r) => (float) $r->cost)->all();
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / count($values);
        $std = sqrt($variance);
        $threshold = $mean + ($this->sigma * $std);

        $acked = AnomalyAck::query()->pluck('day')->map(fn ($d) => (string) $d)->all();

        $anomalies = [];
        foreach ($rows as $row) {
            $cost = (float) $row->cost;
            if ($cost > $threshold && $cost > $this->floor) {
                $day = (string) $row->day;
                $anomalies[] = [
                    'day' => $day,
                    'cost' => round($cost, 6),
                    'expected' => round($threshold, 6),
                    'severity' => $std > 0 ? round(($cost - $mean) / $std, 2) : null,
                    'acked' => in_array($day, $acked, true),
                ];
            }
        }

        return $anomalies;
    }
}
