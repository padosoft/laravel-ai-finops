<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

class PriceSnapshot extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = ['model', 'provider', 'input_cost_per_token', 'output_cost_per_token', 'source', 'captured_at'];

    protected $casts = [
        'input_cost_per_token' => 'float',
        'output_cost_per_token' => 'float',
        'captured_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'price_snapshots';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }
}
