<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Ledger;

use Illuminate\Contracts\Config\Repository as Config;
use Padosoft\LaravelAiFinOps\Contracts\UsageRecorder;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;

/**
 * Writes metered calls to the append-only usage ledger. No-ops when the package
 * or metering is disabled, so listeners can call it unconditionally.
 */
class DatabaseUsageRecorder implements UsageRecorder
{
    public function __construct(private readonly Config $config) {}

    public function record(AiCallEnvelope $envelope): void
    {
        if (! $this->config->get('ai-finops.enabled', true)) {
            return;
        }

        if (! $this->config->get('ai-finops.metering', true)) {
            return;
        }

        UsageRecord::fromEnvelope($envelope)->save();
    }
}
