<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Events;

use Padosoft\LaravelAiFinOps\Models\AlertChannel;

/** Fired when an operator tests an alert channel; the host app performs delivery. */
class AlertChannelTested
{
    public function __construct(public readonly AlertChannel $channel) {}
}
