<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Models;

use Illuminate\Database\Eloquent\Model;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Enums\BudgetScope;
use Padosoft\LaravelAiFinOps\Enums\PolicyAction;

/**
 * A declarative spend policy. When all set conditions hold for a call, its action
 * applies. Conditions: scope match (scope_type/scope_id), min_cost (estimated cost
 * threshold), model_match. Evaluated in ascending `priority`.
 *
 * @property string $action
 * @property string|null $action_param
 */
class Policy extends Model
{
    protected $fillable = [
        'name', 'scope_type', 'scope_id', 'min_cost', 'model_match',
        'action', 'action_param', 'priority', 'enabled',
    ];

    protected $casts = [
        'min_cost' => 'float',
        'priority' => 'int',
        'enabled' => 'bool',
    ];

    public function getTable(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'policies';
    }

    public function getConnectionName(): ?string
    {
        return config('ai-finops.storage.connection') ?? parent::getConnectionName();
    }

    public function action(): PolicyAction
    {
        return PolicyAction::from($this->action);
    }

    /** True when every set condition matches the envelope. */
    public function matches(AiCallEnvelope $envelope): bool
    {
        if (! $this->scopeMatches($envelope)) {
            return false;
        }

        if ($this->model_match !== null && $this->model_match !== $envelope->model) {
            return false;
        }

        if ($this->min_cost !== null && $envelope->cost->total < (float) $this->min_cost) {
            return false;
        }

        return true;
    }

    private function scopeMatches(AiCallEnvelope $envelope): bool
    {
        $scope = BudgetScope::from($this->scope_type);

        if ($scope === BudgetScope::Global) {
            return true;
        }

        $value = match ($scope) {
            BudgetScope::Tenant => $envelope->tenantId,
            BudgetScope::User => $envelope->userId,
            BudgetScope::CostCenter => $envelope->costCenter,
            BudgetScope::Provider => $envelope->provider,
            BudgetScope::Model => $envelope->model,
            BudgetScope::Agent => $envelope->agentStep,
            BudgetScope::Purpose => $envelope->purposeTag,
            BudgetScope::Global => null,
        };

        return $value !== null && (string) $value === (string) $this->scope_id;
    }
}
