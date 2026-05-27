<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Policies;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\QueryException;
use Padosoft\LaravelAiFinOps\Budgets\BudgetResolver;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\PolicyDecision;
use Padosoft\LaravelAiFinOps\Models\KillSwitch;

/**
 * Decides whether a call may proceed. M2 logic, in order:
 *   1. global kill switch (config or stored)
 *   2. scoped kill switch (provider/tenant)
 *   3. hard budget already exceeded (when enforcement enabled)
 * Advanced policy actions (throttle/downgrade/queue/approval) arrive in M3.
 */
class PolicyEngine
{
    public function __construct(
        private readonly Config $config,
        private readonly BudgetResolver $budgets,
    ) {}

    public function evaluate(AiCallEnvelope $envelope): PolicyDecision
    {
        if ((bool) $this->config->get('ai-finops.kill_switch', false)) {
            return PolicyDecision::block('global kill switch active (config)');
        }

        if (($killReason = $this->killSwitchReason($envelope)) !== null) {
            return PolicyDecision::block($killReason);
        }

        if ($this->config->get('ai-finops.enforcement', true)) {
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

        return PolicyDecision::allow();
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
