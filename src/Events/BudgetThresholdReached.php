<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Events;

use Padosoft\LaravelAiFinOps\Data\BudgetStatus;
use Padosoft\LaravelAiFinOps\Models\AlertChannel;
use Padosoft\LaravelAiFinOps\Models\AlertRule;

/**
 * Emitted when a budget reaches an alert rule's threshold. The host app listens
 * to deliver via the channel (mail/Slack/Teams/webhook/SMS) using channel config;
 * the package detects + logs, delivery integration is the host's responsibility.
 */
class BudgetThresholdReached
{
    public function __construct(
        public readonly AlertRule $rule,
        public readonly BudgetStatus $status,
        public readonly ?AlertChannel $channel = null,
    ) {}
}
