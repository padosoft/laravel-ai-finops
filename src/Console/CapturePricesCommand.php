<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Console;

use Illuminate\Console\Command;
use Padosoft\LaravelAiFinOps\PriceWatch\PriceWatchService;

class CapturePricesCommand extends Command
{
    protected $signature = 'ai-finops:capture-prices';

    protected $description = 'Snapshot current prices for watched models (provider price-change watcher).';

    public function handle(PriceWatchService $service): int
    {
        $count = $service->capture();

        $this->info("Captured {$count} price snapshot(s).");

        return self::SUCCESS;
    }
}
