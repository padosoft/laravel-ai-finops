<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;
use Padosoft\LaravelAiFinOps\Pricing\ModelPrice;

/**
 * Local Padosoft price override. When present it WINS over the LiteLLM mirror
 * (see config ai-finops.pricing.overrides_win).
 *
 * @property string $model
 * @property string|null $provider
 */
class PricingOverride extends Model
{
    protected $guarded = [];

    protected $casts = [
        'input_cost_per_token' => 'float',
        'output_cost_per_token' => 'float',
        'cache_read_cost_per_token' => 'float',
        'cache_write_cost_per_token' => 'float',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'pricing_overrides';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }

    public function toModelPrice(): ModelPrice
    {
        // Operators may enter feed-less prices per-million (e.g. regolo.ai, EUR);
        // normalize to the per-single-token contract used everywhere downstream.
        $divisor = $this->unit === 'per_million' ? 1_000_000.0 : 1.0;

        return new ModelPrice(
            model: $this->model,
            inputPerToken: (float) $this->input_cost_per_token / $divisor,
            outputPerToken: (float) $this->output_cost_per_token / $divisor,
            cacheReadPerToken: $this->cache_read_cost_per_token !== null ? (float) $this->cache_read_cost_per_token / $divisor : null,
            cacheWritePerToken: $this->cache_write_cost_per_token !== null ? (float) $this->cache_write_cost_per_token / $divisor : null,
            currency: (string) $this->currency,
            provider: $this->provider,
            source: 'override',
        );
    }
}
