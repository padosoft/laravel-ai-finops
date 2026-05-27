<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Notify when a budget's consumption reaches threshold_pct. `last_notified_pct`
 * de-dupes repeated alerts within a period and is reset when spend drops back.
 *
 * @property int $budget_id
 * @property int $threshold_pct
 */
class AlertRule extends Model
{
    protected $fillable = ['name', 'budget_id', 'threshold_pct', 'channel_id', 'enabled', 'last_notified_pct'];

    protected $casts = [
        'budget_id' => 'int',
        'threshold_pct' => 'int',
        'channel_id' => 'int',
        'enabled' => 'bool',
        'last_notified_pct' => 'int',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'alert_rules';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }
}
