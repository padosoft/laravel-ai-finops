<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Contracts;

interface PricingSource
{
    /**
     * The full model→attributes map (LiteLLM-style attribute arrays keyed by model).
     *
     * @return array<string,array<string,mixed>>
     */
    public function all(): array;

    /**
     * Refresh the underlying data (e.g. re-fetch the mirror). Returns the number
     * of models available after the refresh.
     */
    public function sync(): int;

    /** Identifier used as the price `source` provenance. */
    public function name(): string;
}
