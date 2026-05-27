<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Budgets;

use Illuminate\Support\Collection;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Enums\BudgetScope;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Throwable;

/**
 * Finds the budgets that apply to a given call and evaluates their consumption.
 * Used by the dashboard (reporting) and the enforcer (M2.2).
 */
class BudgetResolver
{
    /**
     * Enabled budgets whose scope matches the envelope (Global always applies;
     * a scoped budget applies when the envelope's matching value equals scope_id).
     *
     * @return Collection<int,Budget>
     */
    public function applicableTo(AiCallEnvelope $envelope): Collection
    {
        try {
            $budgets = Budget::query()->where('enabled', true)->get();
        } catch (Throwable) {
            return collect();
        }

        return $budgets->filter(fn (Budget $budget) => $this->matches($budget, $envelope))->values();
    }

    private function matches(Budget $budget, AiCallEnvelope $envelope): bool
    {
        $scope = $budget->scope();

        if ($scope === BudgetScope::Global) {
            return true;
        }

        $value = $this->envelopeValue($scope, $envelope);

        return $value !== null && (string) $value === (string) $budget->scope_id;
    }

    private function envelopeValue(BudgetScope $scope, AiCallEnvelope $envelope): string|int|null
    {
        return match ($scope) {
            BudgetScope::Tenant => $envelope->tenantId,
            BudgetScope::User => $envelope->userId,
            BudgetScope::CostCenter => $envelope->costCenter,
            BudgetScope::Provider => $envelope->provider,
            BudgetScope::Model => $envelope->model,
            BudgetScope::Agent => $envelope->agentStep,
            BudgetScope::Purpose => $envelope->purposeTag,
            BudgetScope::Global => null,
        };
    }
}
