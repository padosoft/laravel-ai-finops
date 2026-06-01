<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Contracts;

use Padosoft\LaravelAiFinOps\Data\TokenUsage;

interface TokenEstimator
{
    /**
     * Estimate token usage for a prompt (a plain string or a chat-messages array)
     * on the given model. Output tokens are unknown pre-flight, so callers treat
     * the returned `input` as the prompt size and may project output separately.
     *
     * @param  string|array<int,mixed>  $prompt
     */
    public function estimate(string|array $prompt, ?string $model = null): TokenUsage;
}
