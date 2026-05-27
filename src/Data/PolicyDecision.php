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
    ) {}

    public static function allow(string $reason = 'within budget'): self
    {
        return new self(PolicyAction::Allow, $reason);
    }

    public static function block(string $reason, ?int $budgetId = null): self
    {
        return new self(PolicyAction::Block, $reason, $budgetId);
    }

    public function blocked(): bool
    {
        return $this->action === PolicyAction::Block;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'reason' => $this->reason,
            'budget_id' => $this->budgetId,
            'suggested_model' => $this->suggestedModel,
        ];
    }
}
