<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;

/**
 * Append-only ledger row for a metered AI call. Read-heavy; writes happen only
 * via the metering hook / manual reporting. `updated_at` is intentionally absent
 * (the ledger is immutable).
 *
 * @property string $trace_id
 * @property string $provider
 * @property string $model
 * @property string $status
 * @property float $cost_total
 * @property string $currency
 */
class UsageRecord extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'tokens_input' => 'int',
        'tokens_output' => 'int',
        'tokens_cached' => 'int',
        'tokens_reasoning' => 'int',
        'cost_input' => 'decimal:8',
        'cost_output' => 'decimal:8',
        'cost_cached' => 'decimal:8',
        'cost_total' => 'decimal:8',
        'latency_ms' => 'int',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'usage_ledger';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }

    public static function fromEnvelope(AiCallEnvelope $envelope): self
    {
        return new self($envelope->toLedgerRow());
    }
}
