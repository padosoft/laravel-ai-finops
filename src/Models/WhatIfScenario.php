<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string,mixed> $payload
 * @property array<string,mixed>|null $result
 */
class WhatIfScenario extends Model
{
    protected $fillable = ['name', 'payload', 'result'];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'whatif_scenarios';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }
}
