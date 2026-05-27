<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Policies;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\QueryException;
use Padosoft\LaravelAiFinOps\Budgets\BudgetResolver;
use Padosoft\LaravelAiFinOps\Contracts\GuardrailProvider;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\PolicyDecision;
use Padosoft\LaravelAiFinOps\Enums\PolicyAction;
use Padosoft\LaravelAiFinOps\Models\KillSwitch;
use Padosoft\LaravelAiFinOps\Models\Policy;
use Padosoft\LaravelAiFinOps\Models\SpendApproval;

/**
 * Decides whether a call may proceed, in order:
 *   1. global kill switch (config or stored)
 *   2. scoped kill switch (provider/tenant)
 *   3. guardrail violation (when guardrail-linked spend is enabled)
 *   4. hard budget exceeded / would-exceed (when enforcement enabled)
 *   5. declarative policies (block/require_approval/downgrade/throttle/queue)
 * Block and RequireApproval halt the call (enforced); Downgrade/Throttle/Queue are
 * surfaced as advisory decisions (auto-application is a pipeline follow-up).
 */
class PolicyEngine
{
    public function __construct(
        private readonly Config $config,
        private readonly BudgetResolver $budgets,
        private readonly GuardrailProvider $guardrail,
    ) {}

    public function evaluate(AiCallEnvelope $envelope): PolicyDecision
    {
        if ((bool) $this->config->get('ai-finops.kill_switch', false)) {
            return PolicyDecision::block('global kill switch active (config)');
        }

        if (($killReason = $this->killSwitchReason($envelope)) !== null) {
            return PolicyDecision::block($killReason);
        }

        if ($this->config->get('ai-finops.features.guardrail_linked_spend', false)
            && ($violation = $this->guardrail->violation($envelope)) !== null) {
            return PolicyDecision::block("guardrail violation: {$violation}");
        }

        $enforce = (bool) $this->config->get('ai-finops.enforcement', true);

        if ($enforce) {
            // Include the in-flight estimated cost so a call that WOULD push a hard
            // budget over its limit is blocked before it runs (not one call late).
            $inFlight = max(0.0, $envelope->cost->total);

            foreach ($this->budgets->applicableTo($envelope) as $budget) {
                if (! $budget->hard) {
                    continue;
                }

                $status = $budget->status();
                $limit = (float) $budget->limit_amount;

                if ($status->exceeded() || ($limit > 0 && ($status->spent + $inFlight) >= $limit)) {
                    return PolicyDecision::block(
                        "hard budget exceeded: {$budget->name}",
                        (int) $budget->id,
                    );
                }
            }
        }

        if (($policyDecision = $this->evaluatePolicies($envelope, $enforce)) !== null) {
            return $policyDecision;
        }

        return PolicyDecision::allow();
    }

    private function evaluatePolicies(AiCallEnvelope $envelope, bool $enforce): ?PolicyDecision
    {
        try {
            $policies = Policy::query()->where('enabled', true)->orderBy('priority')->orderBy('id')->get();
        } catch (QueryException) {
            return null;
        }

        foreach ($policies as $policy) {
            if (! $policy->matches($envelope)) {
                continue;
            }

            // Observability mode (enforcement off): halting policy actions are skipped
            // so they don't abort calls; advisory actions are still surfaced.
            if (! $enforce && in_array($policy->action(), [PolicyAction::Block, PolicyAction::RequireApproval], true)) {
                continue;
            }

            return match ($policy->action()) {
                PolicyAction::Allow => PolicyDecision::allow("policy: {$policy->name}"),
                PolicyAction::Block => PolicyDecision::block("policy: {$policy->name}", policyId: (int) $policy->id),
                PolicyAction::Downgrade => PolicyDecision::downgrade((string) $policy->action_param, "policy: {$policy->name}", (int) $policy->id),
                PolicyAction::Throttle => PolicyDecision::throttle("policy: {$policy->name}", (int) $policy->id),
                PolicyAction::Queue => PolicyDecision::queue("policy: {$policy->name}", (int) $policy->id),
                PolicyAction::RequireApproval => $this->requireApproval($policy, $envelope),
            };
        }

        return null;
    }

    private function requireApproval(Policy $policy, AiCallEnvelope $envelope): PolicyDecision
    {
        $approval = SpendApproval::create([
            'provider' => $envelope->provider,
            'model' => $envelope->model,
            'tenant_id' => $envelope->tenantId !== null ? (string) $envelope->tenantId : null,
            'cost_center' => $envelope->costCenter,
            'estimated_cost' => $envelope->cost->total,
            'currency' => $envelope->cost->currency,
            'status' => 'pending',
            'policy_id' => $policy->id,
            'reason' => "policy: {$policy->name}",
        ]);

        return PolicyDecision::requireApproval("approval required (policy: {$policy->name})", (int) $approval->id, (int) $policy->id);
    }

    private function killSwitchReason(AiCallEnvelope $envelope): ?string
    {
        try {
            $switches = KillSwitch::query()->where('active', true)->get();
        } catch (QueryException) {
            return null;
        }

        foreach ($switches as $switch) {
            $match = match ($switch->scope_type) {
                'global' => true,
                'provider' => (string) $switch->scope_id === $envelope->provider,
                'tenant' => $envelope->tenantId !== null && (string) $switch->scope_id === (string) $envelope->tenantId,
                default => false,
            };

            if ($match) {
                return "kill switch active: {$switch->scope_type}".($switch->scope_id ? " ({$switch->scope_id})" : '');
            }
        }

        return null;
    }
}
