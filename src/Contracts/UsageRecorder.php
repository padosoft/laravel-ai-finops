<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Contracts;

use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;

interface UsageRecorder
{
    /**
     * Persist a metered AI call. Implementations must be safe to call from event
     * listeners and should no-op when metering is disabled.
     */
    public function record(AiCallEnvelope $envelope): void;
}
