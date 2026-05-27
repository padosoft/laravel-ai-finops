<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A delivery channel for budget alerts. `config` holds secrets (webhook URL,
 * recipients) and is never serialized raw — use safeArray() for API output.
 *
 * @property string $type
 * @property array<string,mixed>|null $config
 */
class AlertChannel extends Model
{
    protected $fillable = ['type', 'name', 'config', 'enabled'];

    protected $hidden = ['config'];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'bool',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'alert_channels';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }

    /** @return array<string,mixed> */
    public function safeArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'enabled' => $this->enabled,
            'has_config' => ! empty($this->config),
        ];
    }
}
