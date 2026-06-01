<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing\Cost;

use Padosoft\LaravelAiFinOps\Contracts\TokenEstimator;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Throwable;
use Yethee\Tiktoken\EncoderProvider;

/**
 * Exact token counting via the optional `yethee/tiktoken` package (BPE for OpenAI
 * encodings). Exact for OpenAI / OpenAI-compatible models; a close proxy (±5–10%)
 * for others. Bound only when the package is installed; falls back to the heuristic
 * on any tokenizer error so estimation never breaks metering.
 */
class TiktokenTokenEstimator implements TokenEstimator
{
    public function __construct(
        private readonly EncoderProvider $provider = new EncoderProvider,
        private readonly HeuristicTokenEstimator $fallback = new HeuristicTokenEstimator,
    ) {}

    public function estimate(string|array $prompt, ?string $model = null): TokenUsage
    {
        $text = $this->flatten($prompt);

        try {
            $encoder = $this->provider->getForModel($this->encodingModel($model));

            return new TokenUsage(input: count($encoder->encode($text)));
        } catch (Throwable) {
            return $this->fallback->estimate($prompt, $model);
        }
    }

    private function encodingModel(?string $model): string
    {
        $m = strtolower((string) $model);

        // o200k_base family (gpt-4o / o-series); else cl100k_base. Map to a known
        // model name the provider recognizes for the right encoding.
        if ($m === '' || str_contains($m, '4o') || str_contains($m, 'o1') || str_contains($m, 'o3') || str_contains($m, 'gpt-5')) {
            return 'gpt-4o';
        }

        return 'gpt-4';
    }

    /**
     * @param  string|array<int,mixed>  $prompt
     */
    private function flatten(string|array $prompt): string
    {
        if (is_string($prompt)) {
            return $prompt;
        }

        $parts = [];
        array_walk_recursive($prompt, static function ($value) use (&$parts): void {
            if (is_string($value)) {
                $parts[] = $value;
            }
        });

        return implode(' ', $parts);
    }
}
