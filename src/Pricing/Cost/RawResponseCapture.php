<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing\Cost;

use Padosoft\LaravelAiFinOps\Data\TokenUsage;

/**
 * Request/job-scoped buffer of provider usage/cost blocks captured from raw HTTP
 * responses BEFORE laravel/ai normalizes them away (it keeps tokens only). Only the
 * usage/cost block + response id are stored — never message content (PII/secrets).
 * The metering hook drains captures recorded since the previous metered call and
 * sums them, which also covers laravel/ai multi-step tool loops.
 */
class RawResponseCapture
{
    /** @var array<int,array<string,mixed>> */
    private array $captures = [];

    /** @param array<string,mixed> $capture */
    public function push(array $capture): void
    {
        $this->captures[] = $capture;
    }

    public function isEmpty(): bool
    {
        return $this->captures === [];
    }

    /**
     * Return and clear the captures recorded so far.
     *
     * @return array<int,array<string,mixed>>
     */
    public function drain(): array
    {
        $captures = $this->captures;
        $this->captures = [];

        return $captures;
    }

    /**
     * Sum cost + native tokens across the currently buffered captures and clear
     * them. Returns null when nothing was captured.
     *
     * @return array{cost: float, currency: string, tokens: TokenUsage}|null
     */
    public function sumCost(): ?array
    {
        if ($this->isEmpty()) {
            return null;
        }

        $cost = 0.0;
        $currency = 'credits';
        $input = $output = $cached = $reasoning = 0;

        foreach ($this->drain() as $c) {
            $cost += (float) ($c['cost'] ?? 0.0);
            $currency = (string) ($c['currency'] ?? $currency);
            $input += (int) ($c['prompt_tokens'] ?? 0);
            $output += (int) ($c['completion_tokens'] ?? 0);
            $cached += (int) ($c['cached_tokens'] ?? 0);
            $reasoning += (int) ($c['reasoning_tokens'] ?? 0);
        }

        return [
            'cost' => $cost,
            'currency' => $currency,
            'tokens' => new TokenUsage(input: $input, output: $output, cached: $cached, reasoning: $reasoning),
        ];
    }
}
