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
        return new ModelPrice(
            model: $this->model,
            inputPerToken: (float) $this->input_cost_per_token,
            outputPerToken: (float) $this->output_cost_per_token,
            cacheReadPerToken: $this->cache_read_cost_per_token !== null ? (float) $this->cache_read_cost_per_token : null,
            cacheWritePerToken: $this->cache_write_cost_per_token !== null ? (float) $this->cache_write_cost_per_token : null,
            currency: (string) $this->currency,
            provider: $this->provider,
            source: 'override',
        );
    }
}
