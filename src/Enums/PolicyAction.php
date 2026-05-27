<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Enums;

/**
 * Outcome of policy evaluation. M2 implements Allow/Block; the advanced actions
 * (Throttle/Downgrade/Queue/RequireApproval) are added in M3.
 */
enum PolicyAction: string
{
    case Allow = 'allow';
    case Block = 'block';
    case Throttle = 'throttle';
    case Downgrade = 'downgrade';
    case Queue = 'queue';
    case RequireApproval = 'require_approval';
}
