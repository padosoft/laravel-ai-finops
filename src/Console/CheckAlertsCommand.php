<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Console;

use Illuminate\Console\Command;
use Padosoft\LaravelAiFinOps\Alerts\AlertDispatcher;

class CheckAlertsCommand extends Command
{
    protected $signature = 'ai-finops:check-alerts';

    protected $description = 'Evaluate budget alert rules and dispatch threshold notifications.';

    public function handle(AlertDispatcher $dispatcher): int
    {
        $triggered = $dispatcher->evaluate();

        $this->info("Alert rules evaluated. Triggered: {$triggered}.");

        return self::SUCCESS;
    }
}
