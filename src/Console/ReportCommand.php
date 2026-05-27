<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;

class ReportCommand extends Command
{
    protected $signature = 'ai-finops:report {--days=30 : Look-back window in days}';

    protected $description = 'Print an AI spend summary from the usage ledger.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $base = UsageRecord::query()->where('created_at', '>=', $since);

        $calls = (clone $base)->count();
        $cost = round((float) (clone $base)->sum('cost_total'), 6);
        // Use base currency — totals are stored in base; display currency has no FX conversion yet.
        $currency = (string) config('ai-finops.currency.base', 'USD');

        $this->info("AI FinOps report — last {$days} day(s)");
        $this->line("Calls: {$calls}");
        $this->line("Total cost: {$cost} {$currency}");

        $top = (clone $base)->groupBy('model')
            ->orderByDesc('cost')
            ->limit(5)
            ->get(['model', DB::raw('SUM(cost_total) as cost'), DB::raw('COUNT(*) as calls')]);

        if ($top->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['Model', 'Cost', 'Calls'],
                $top->map(fn ($r) => [$r->model, round((float) $r->cost, 6), $r->calls])->all(),
            );
        }

        return self::SUCCESS;
    }
}
