<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing;

/**
 * Per-token prices for a model, in `currency` (LiteLLM mirror is USD). Costs are
 * per single token (not per 1K/1M). `source` records provenance ("litellm" or
 * "override") for auditing which price won.
 */
final readonly class ModelPrice
{
    public function __construct(
        public string $model,
        public float $inputPerToken = 0.0,
        public float $outputPerToken = 0.0,
        public ?float $cacheReadPerToken = null,
        public ?float $cacheWritePerToken = null,
        public string $currency = 'USD',
        public ?string $provider = null,
        public string $source = 'litellm',
    ) {}

    /**
     * Build from a LiteLLM-style attribute map.
     *
     * @param  array<string,mixed>  $attr
     */
    public static function fromLiteLLM(string $model, array $attr, string $source = 'litellm'): self
    {
        return new self(
            model: $model,
            inputPerToken: (float) ($attr['input_cost_per_token'] ?? 0.0),
            outputPerToken: (float) ($attr['output_cost_per_token'] ?? 0.0),
            cacheReadPerToken: isset($attr['cache_read_input_token_cost']) ? (float) $attr['cache_read_input_token_cost'] : null,
            cacheWritePerToken: isset($attr['cache_creation_input_token_cost']) ? (float) $attr['cache_creation_input_token_cost'] : null,
            currency: 'USD',
            provider: $attr['litellm_provider'] ?? null,
            source: $source,
        );
    }
}
