<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A flat-rate subscription coverage window (e.g. "claude-max", "openai-pro").
 * While `now()` is inside an active window for a provider, metered calls cost 0 —
 * the subscription already paid for them. When the provider signals the quota is
 * exhausted, the operator shortens `ends_at` and calls revert to paid.
 *
 * @property string $provider
 * @property string $label
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $enabled
 * @property string|null $tenant_id
 * @property string|null $model
 */
class SubscriptionWindow extends Model
{
    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'enabled' => 'boolean',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'subscription_windows';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }

    /**
     * The active window covering this (provider, tenant, model) at the given time,
     * or null. Null tenant/model on a window mean "any". Most recent wins.
     */
    public static function activeFor(string $provider, ?string $tenant, ?string $model, DateTimeInterface $at): ?self
    {
        return static::query()
            ->where('enabled', true)
            ->where('provider', $provider)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at))
            ->where(fn (Builder $q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant))
            ->where(fn (Builder $q) => $q->whereNull('model')->orWhere('model', $model))
            ->orderByDesc('id')
            ->first();
    }
}
