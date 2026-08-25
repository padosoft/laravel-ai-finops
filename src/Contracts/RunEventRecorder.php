<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Contracts;

use Padosoft\LaravelAiFinOps\Data\RunEvent;

/**
 * Persists the step and tool events of an agent run. Separate from
 * {@see UsageRecorder} on purpose: the usage ledger is the cost record and holds
 * exactly one row per billed call, while this holds the shape of the run. Mixing
 * them would double every total in the dashboards.
 *
 * @api
 */
interface RunEventRecorder
{
    public function record(RunEvent $event): void;
}
