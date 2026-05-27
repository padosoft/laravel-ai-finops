<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Padosoft\LaravelAiFinOps\Data\BudgetStatus;
use Padosoft\LaravelAiFinOps\Enums\BudgetPeriod;
use Padosoft\LaravelAiFinOps\Enums\BudgetScope;

/**
 * A spend limit over a scope (global/tenant/user/cost-center/provider/model/...)
 * for a period. Hierarchy is expressed via parent_id. Hard budgets block;
 * soft limits warn.
 *
 * @property int $id
 * @property string $scope_type
 * @property string|null $scope_id
 * @property float $limit_amount
 * @property string $period
 * @property bool $hard
 */
class Budget extends Model
{
    protected $fillable = [
        'name', 'parent_id', 'scope_type', 'scope_id',
        'limit_amount', 'currency', 'period', 'rolling_days',
        'soft_limit_pct', 'hard', 'enabled',
    ];

    protected $casts = [
        'limit_amount' => 'float',
        'rolling_days' => 'int',
        'soft_limit_pct' => 'int',
        'hard' => 'bool',
        'enabled' => 'bool',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'budgets';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scope(): BudgetScope
    {
        return BudgetScope::from($this->scope_type);
    }

    public function period(): BudgetPeriod
    {
        return BudgetPeriod::from($this->period);
    }

    public function currentPeriodStart(?DateTimeInterface $now = null): string
    {
        return $this->period()
            ->currentStart($now ?? now(), (int) $this->rolling_days)
            ->toDateTimeString();
    }

    /** Spend in the current period for this budget's scope. */
    public function spend(?DateTimeInterface $now = null): float
    {
        $start = $this->currentPeriodStart($now);

        $query = UsageRecord::query()->where('created_at', '>=', $start);

        $column = $this->scope()->ledgerColumn();
        if ($column !== null) {
            $query->where($column, (string) $this->scope_id);
        }

        return (float) $query->sum('cost_total');
    }

    public function status(?DateTimeInterface $now = null): BudgetStatus
    {
        return new BudgetStatus(
            budgetId: (int) $this->id,
            name: (string) $this->name,
            limit: (float) $this->limit_amount,
            spent: $this->spend($now),
            currency: (string) $this->currency,
            hard: (bool) $this->hard,
            periodStart: $this->currentPeriodStart($now),
        );
    }
}
