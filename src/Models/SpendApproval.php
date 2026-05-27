<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A pending/approved/rejected request created when a require_approval policy fires.
 *
 * @property string $status
 * @property float $estimated_cost
 */
class SpendApproval extends Model
{
    protected $fillable = [
        'provider', 'model', 'tenant_id', 'cost_center', 'estimated_cost',
        'currency', 'status', 'policy_id', 'reason', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'estimated_cost' => 'float',
        'decided_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'approvals';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }
}
