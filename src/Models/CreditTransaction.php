<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['pool_id', 'amount', 'type', 'reason', 'created_at'];

    protected $casts = [
        'amount' => 'float',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'credit_transactions';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }
}
