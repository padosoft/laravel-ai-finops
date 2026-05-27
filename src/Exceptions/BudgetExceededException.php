<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Thrown when enforcement blocks a call. Extends HttpException so Laravel renders
 * the configured status (default 402 Payment Required) for HTTP consumers, while
 * still aborting the AI call when thrown from the metering hook.
 */
class BudgetExceededException extends HttpException
{
    public function __construct(
        public readonly string $blockReason = 'AI budget/policy block',
        public readonly ?int $budgetId = null,
        ?Throwable $previous = null,
    ) {
        $status = (int) config('ai-finops.block_status', 402);

        // Use a generic HTTP message to avoid leaking budget names or tenant IDs to callers.
        // The detailed reason is available via $this->blockReason for internal logging.
        parent::__construct($status, 'AI spend limit reached', $previous, [], 0);
    }
}
