<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Enums;

/**
 * How a metered call's cost was derived (recorded on the ledger for analysis):
 * the cascade prefers the truest number available.
 */
enum CostMethod: string
{
    /** The provider returned the real billed cost (e.g. OpenRouter usage.cost). */
    case Actual = 'actual';

    /** Actual token usage × our tariff (the common default). */
    case Computed = 'computed';

    /** Tokens were estimated (no usage in the response) × our tariff. */
    case Estimated = 'estimated';

    /** Covered by an active flat-rate subscription window → billed at zero. */
    case Covered = 'covered';
}
