<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Scoped emergency stop. An active row blocks matching calls regardless of budget.
 * scope_type: global|provider|tenant|feature.
 *
 * @property string $scope_type
 * @property string|null $scope_id
 * @property bool $active
 */
class KillSwitch extends Model
{
    protected $guarded = [];

    protected $casts = [
        'active' => 'bool',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'kill_switches';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }
}
