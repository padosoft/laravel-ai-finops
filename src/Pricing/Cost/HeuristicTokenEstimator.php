<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing\Cost;

use Padosoft\LaravelAiFinOps\Contracts\TokenEstimator;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;

/**
 * Zero-dependency token estimator: max(chars/4, words×1.3). Good to ±10–20% on
 * English prose, worse on code / non-Latin. Used when no exact tokenizer (the
 * optional yethee/tiktoken) is installed. Counts are always flagged as estimated.
 */
class HeuristicTokenEstimator implements TokenEstimator
{
    public function estimate(string|array $prompt, ?string $model = null): TokenUsage
    {
        $text = $this->flatten($prompt);

        $chars = mb_strlen($text);
        $words = str_word_count($text);

        $input = max((int) ceil($chars / 4), (int) ceil($words * 1.3));

        return new TokenUsage(input: $input);
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
