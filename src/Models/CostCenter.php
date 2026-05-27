<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A chargeback/showback allocation target. The ledger stores `cost_center` as a
 * free string (the center's `code`); this table adds metadata for reporting.
 *
 * @property string $code
 * @property string $name
 */
class CostCenter extends Model
{
    protected $fillable = ['code', 'name', 'owner', 'department', 'enabled'];

    protected $casts = [
        'enabled' => 'bool',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'cost_centers';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }
}
