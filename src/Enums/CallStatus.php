<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Enums;

enum CallStatus: string
{
    /** Cost was estimated pre-flight (no provider charge incurred yet). */
    case Estimated = 'estimated';

    /** Actual usage was recorded post-flight from the provider response. */
    case Recorded = 'recorded';

    /** The call was blocked by a budget/policy before execution. */
    case Blocked = 'blocked';

    /** The provider call failed; partial/zero usage may apply. */
    case Failed = 'failed';

    /**
     * The call fell within an active flat-rate subscription window (e.g. Claude
     * Max) — tokens are recorded for visibility, but cost is zero because the
     * subscription already paid for it.
     */
    case Covered = 'covered';
}
