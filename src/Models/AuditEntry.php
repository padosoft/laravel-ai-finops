<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable audit record of a governance mutation (budget/policy/kill-switch/etc).
 *
 * @property string $event
 * @property string $subject_type
 */
class AuditEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'audit_log';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }
}
