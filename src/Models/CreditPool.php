<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property float $balance
 */
class CreditPool extends Model
{
    protected $fillable = ['name', 'scope_type', 'scope_id', 'balance', 'currency', 'enabled'];

    protected $casts = [
        'balance' => 'float',
        'enabled' => 'bool',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'credit_pools';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }
}
