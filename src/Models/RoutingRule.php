<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<int,string> $candidates
 * @property float|null $min_quality
 */
class RoutingRule extends Model
{
    protected $fillable = ['name', 'scope_type', 'scope_id', 'candidates', 'min_quality', 'enabled'];

    protected $casts = [
        'candidates' => 'array',
        'min_quality' => 'float',
        'enabled' => 'bool',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'routing_rules';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }
}
