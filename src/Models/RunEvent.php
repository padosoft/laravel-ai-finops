<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Padosoft\LaravelAiFinOps\Data\RunEvent as RunEventData;
use Padosoft\LaravelAiFinOps\Enums\RunEventKind;
use Padosoft\LaravelAiFinOps\Enums\RunEventStatus;

/**
 * Append-only row describing one step or tool invocation of an agent run.
 *
 * @property string $invocation_id
 * @property string $kind
 * @property string $status
 * @property int|null $duration_ms
 */
class RunEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'step_number' => 'int',
        'is_final_step' => 'bool',
        'tokens_input' => 'int',
        'tokens_output' => 'int',
        'tokens_cached' => 'int',
        'tokens_reasoning' => 'int',
        'cost_total' => 'decimal:8',
        'duration_ms' => 'int',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'run_events';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }

    public static function fromData(RunEventData $event): self
    {
        return new self($event->toRow());
    }

    /** @param  Builder<self>  $query */
    public function scopeSteps(Builder $query): void
    {
        $query->where('kind', RunEventKind::Step->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeTools(Builder $query): void
    {
        $query->where('kind', RunEventKind::Tool->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeFailed(Builder $query): void
    {
        $query->where('status', RunEventStatus::Failed->value);
    }
}
