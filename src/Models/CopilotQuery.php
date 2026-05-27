<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

class CopilotQuery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['question', 'answer', 'configured', 'created_at'];

    protected $casts = [
        'configured' => 'bool',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'copilot_queries';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }
}
