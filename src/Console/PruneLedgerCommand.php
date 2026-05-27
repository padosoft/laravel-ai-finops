<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Console;

use Illuminate\Console\Command;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;

class PruneLedgerCommand extends Command
{
    protected $signature = 'ai-finops:prune {--days= : Override retention window (defaults to config retention_days)}';

    protected $description = 'Delete usage-ledger rows older than the retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('ai-finops.storage.retention_days', 730));

        if ($days < 1) {
            $this->error('Retention days must be >= 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = UsageRecord::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} ledger row(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
