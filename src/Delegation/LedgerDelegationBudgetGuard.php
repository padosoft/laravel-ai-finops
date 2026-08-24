<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Delegation;

use Illuminate\Contracts\Config\Repository as Config;
use Padosoft\Iam\Contracts\Delegation\BudgetVerdict;
use Padosoft\Iam\Contracts\Delegation\DelegationBudgetGuard;
use Padosoft\Iam\Contracts\Delegation\DelegationGrant;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Support\FxConverter;

/**
 * The ledger-backed meter behind IAM budget-bounded delegation: the delegation
 * grant carries the caps (amount / tokens / calls, approved inside the user's
 * bound consent), this guard answers "has the agent consumed them?" by summing
 * the append-only usage ledger for the grant's `delegation_grant_id` rows.
 *
 * Called by laravel-iam-agents on EVERY RFC 8693 token exchange: a deny verdict
 * refuses the exchange (`delegation_budget_exhausted`), so an over-budget agent
 * is stopped within one delegated-token TTL. The verdict is a point-in-time
 * read — the ledger stays the single source of truth, nothing is reserved.
 *
 * Fail-closed division of labour: the guard never guesses. A grant WITHOUT a
 * budget is allowed untouched (nothing to enforce); the caller (iam-agents)
 * refuses budgeted grants when NO guard is bound — that half is not ours.
 */
final class LedgerDelegationBudgetGuard implements DelegationBudgetGuard
{
    public function __construct(
        private readonly Config $config,
        private readonly FxConverter $fx,
    ) {}

    public function verdict(DelegationGrant $grant): BudgetVerdict
    {
        $budget = $grant->budget;
        if ($budget === null) {
            return BudgetVerdict::allow();
        }

        /** @var object{calls: int, tokens: int|null, spent: float|null}|null $usage */
        $usage = UsageRecord::query()
            ->where('delegation_grant_id', $grant->id)
            ->selectRaw(
                'COUNT(*) as calls, '
                .'SUM(tokens_input + tokens_output + tokens_reasoning) as tokens, '
                .'SUM(cost_total) as spent'
            )
            ->first();

        $calls = (int) ($usage->calls ?? 0);
        $tokens = (int) ($usage->tokens ?? 0);
        $spent = $this->spentIn($budget->currency, (float) ($usage->spent ?? 0.0));

        $remaining = [];

        if ($budget->calls !== null) {
            if ($calls >= $budget->calls) {
                return BudgetVerdict::deny(sprintf('calls %d/%d', $calls, $budget->calls));
            }
            $remaining['calls'] = $budget->calls - $calls;
        }

        if ($budget->tokens !== null) {
            if ($tokens >= $budget->tokens) {
                return BudgetVerdict::deny(sprintf('tokens %d/%d', $tokens, $budget->tokens));
            }
            $remaining['tokens'] = $budget->tokens - $tokens;
        }

        if ($budget->amount !== null) {
            if ($spent >= $budget->amount) {
                return BudgetVerdict::deny(sprintf(
                    'amount %.2f/%.2f %s',
                    $spent,
                    $budget->amount,
                    $budget->currency,
                ));
            }
            $remaining['amount'] = round($budget->amount - $spent, 8);
        }

        return BudgetVerdict::allow($remaining);
    }

    /**
     * The ledger records in the FinOps base currency; the budget cap lives in the
     * currency the USER approved. Convert ledger→budget so the comparison honours
     * the consent (FxConverter falls back 1:1 when no fx provider is configured).
     */
    private function spentIn(string $budgetCurrency, float $ledgerTotal): float
    {
        $base = (string) $this->config->get('ai-finops.currency.base', 'USD');

        return $this->fx->convert($ledgerTotal, $base, $budgetCurrency);
    }
}
