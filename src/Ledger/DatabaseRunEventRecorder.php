<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Ledger;

use Padosoft\LaravelAiFinOps\Contracts\RunEventRecorder;
use Padosoft\LaravelAiFinOps\Data\RunEvent;
use Padosoft\LaravelAiFinOps\Models\RunEvent as RunEventModel;
use Throwable;

/**
 * Writes run events to the database.
 *
 * Observability must never be the reason a run fails: a write that throws (table
 * not migrated yet, database briefly away) is swallowed, exactly as the metering
 * hook already treats a missing subscription-windows table.
 */
class DatabaseRunEventRecorder implements RunEventRecorder
{
    public function record(RunEvent $event): void
    {
        try {
            RunEventModel::fromData($event)->save();
        } catch (Throwable) {
            // Intentionally ignored: see the class docblock.
        }
    }
}
