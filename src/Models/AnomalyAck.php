<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Marks a spend-anomaly day as acknowledged. `day` is a plain YYYY-MM-DD string
 * (not date-cast) so it compares directly with the detector's grouped day values.
 *
 * @property string $day
 */
class AnomalyAck extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['day', 'acked_by', 'created_at'];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'anomaly_acks';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }
}
