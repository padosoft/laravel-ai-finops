<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Data;

use Padosoft\LaravelAiFinOps\Enums\PolicyAction;

/** The result of evaluating policies/budgets/kill-switches for a call. */
final readonly class PolicyDecision
{
    public function __construct(
        public PolicyAction $action,
        public string $reason = '',
        public ?int $budgetId = null,
        public ?string $suggestedModel = null,
        public ?int $approvalId = null,
        public ?int $policyId = null,
    ) {}

    public static function allow(string $reason = 'within budget'): self
    {
        return new self(PolicyAction::Allow, $reason);
    }

    public static function block(string $reason, ?int $budgetId = null, ?int $policyId = null): self
    {
        return new self(PolicyAction::Block, $reason, budgetId: $budgetId, policyId: $policyId);
    }

    public static function requireApproval(string $reason, int $approvalId, ?int $policyId = null): self
    {
        return new self(PolicyAction::RequireApproval, $reason, approvalId: $approvalId, policyId: $policyId);
    }

    public static function downgrade(string $suggestedModel, string $reason, ?int $policyId = null): self
    {
        return new self(PolicyAction::Downgrade, $reason, suggestedModel: $suggestedModel, policyId: $policyId);
    }

    public static function throttle(string $reason, ?int $policyId = null): self
    {
        return new self(PolicyAction::Throttle, $reason, policyId: $policyId);
    }

    public static function queue(string $reason, ?int $policyId = null): self
    {
        return new self(PolicyAction::Queue, $reason, policyId: $policyId);
    }

    public function blocked(): bool
    {
        return $this->action === PolicyAction::Block;
    }

    public function requiresApproval(): bool
    {
        return $this->action === PolicyAction::RequireApproval;
    }

    /** Whether the call must be stopped pre-flight (block or pending approval). */
    public function halts(): bool
    {
        return $this->blocked() || $this->requiresApproval();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'reason' => $this->reason,
            'budget_id' => $this->budgetId,
            'suggested_model' => $this->suggestedModel,
            'approval_id' => $this->approvalId,
            'policy_id' => $this->policyId,
        ];
    }
}
