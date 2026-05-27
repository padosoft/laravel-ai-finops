<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Enums;

/**
 * The dimension a budget applies to. Each maps to a ledger column used to scope
 * spend (Global = no filter). The N-level org hierarchy is expressed structurally
 * via Budget::parent_id; this enum is the spend-matching dimension.
 */
enum BudgetScope: string
{
    case Global = 'global';
    case Tenant = 'tenant';
    case User = 'user';
    case CostCenter = 'cost_center';
    case Provider = 'provider';
    case Model = 'model';
    case Agent = 'agent';
    case Purpose = 'purpose';

    /** The ledger column this scope filters on, or null for Global. */
    public function ledgerColumn(): ?string
    {
        return match ($this) {
            self::Global => null,
            self::Tenant => 'tenant_id',
            self::User => 'user_id',
            self::CostCenter => 'cost_center',
            self::Provider => 'provider',
            self::Model => 'model',
            self::Agent => 'agent_step',
            self::Purpose => 'purpose_tag',
        };
    }
}
