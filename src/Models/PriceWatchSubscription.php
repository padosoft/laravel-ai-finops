<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;

class PriceWatchSubscription extends Model
{
    protected $fillable = ['model', 'provider', 'enabled'];

    protected $casts = ['enabled' => 'bool'];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'price_watch_subscriptions';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }
}
