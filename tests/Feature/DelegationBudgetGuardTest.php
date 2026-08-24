<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\Iam\Contracts\Delegation\DelegationBudget;
use Padosoft\Iam\Contracts\Delegation\DelegationBudgetGuard;
use Padosoft\Iam\Contracts\Delegation\DelegationGrant;
use Padosoft\Iam\Contracts\Delegation\DelegationGrantStatus;
use Padosoft\Iam\Contracts\Support\SubjectRef;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Delegation\LedgerDelegationBudgetGuard;
use Padosoft\LaravelAiFinOps\LaravelAiFinOpsServiceProvider;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Support\TraceContext;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class DelegationBudgetGuardTest extends TestCase
{
    use RefreshDatabase;

    private function grant(?DelegationBudget $budget, string $id = 'dgr_test'): DelegationGrant
    {
        return new DelegationGrant(
            id: $id,
            user: new SubjectRef('user', '42'),
            agent: new SubjectRef('agent', 'agt_x'),
            scopes: ['orders:read'],
            purpose: 'Order assistance',
            status: DelegationGrantStatus::Active,
            expiresAt: new \DateTimeImmutable('+1 day'),
            createdAt: new \DateTimeImmutable('-1 hour'),
            budget: $budget,
        );
    }

    private function ledger(string $grantId, float $cost, int $tokensIn = 100, int $tokensOut = 50): void
    {
        UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: uniqid('t', true),
            provider: 'openai',
            model: 'gpt-5.1',
            tokens: new TokenUsage(input: $tokensIn, output: $tokensOut),
            cost: new CostBreakdown(total: $cost, currency: 'USD'),
            delegationGrantId: $grantId,
        ))->save();
    }

    private function guard(): LedgerDelegationBudgetGuard
    {
        return $this->app->make(LedgerDelegationBudgetGuard::class);
    }

    public function test_envelope_pre_delegation_positional_arity_still_constructs(): void
    {
        // Trailing-param BC rule (flow v2.2.1 lesson): every historic positional
        // call site must keep compiling — delegationGrantId is LAST with a default.
        $envelope = new AiCallEnvelope('t1', 'openai', 'gpt-5.1');

        $this->assertNull($envelope->delegationGrantId);
        $this->assertArrayHasKey('delegation_grant_id', $envelope->toLedgerRow());
    }

    public function test_grant_without_budget_is_allowed_untouched(): void
    {
        $verdict = $this->guard()->verdict($this->grant(null));

        $this->assertTrue($verdict->allowed);
    }

    public function test_calls_cap_denies_at_the_cap_with_remaining_before(): void
    {
        $this->ledger('dgr_test', 0.01);
        $this->ledger('dgr_test', 0.01);

        $under = $this->guard()->verdict($this->grant(new DelegationBudget(calls: 3)));
        $this->assertTrue($under->allowed);
        $this->assertSame(1, $under->remaining['calls']);

        $this->ledger('dgr_test', 0.01);
        $at = $this->guard()->verdict($this->grant(new DelegationBudget(calls: 3)));
        $this->assertFalse($at->allowed);
        $this->assertSame('calls 3/3', $at->reason);
    }

    public function test_tokens_cap_sums_input_output_reasoning(): void
    {
        $this->ledger('dgr_test', 0.01, tokensIn: 600, tokensOut: 300); // 900 tokens

        $under = $this->guard()->verdict($this->grant(new DelegationBudget(tokens: 1000)));
        $this->assertTrue($under->allowed);
        $this->assertSame(100, $under->remaining['tokens']);

        $this->ledger('dgr_test', 0.01, tokensIn: 80, tokensOut: 20); // 1000 total
        $at = $this->guard()->verdict($this->grant(new DelegationBudget(tokens: 1000)));
        $this->assertFalse($at->allowed);
        $this->assertSame('tokens 1000/1000', $at->reason);
    }

    public function test_amount_cap_converts_ledger_base_currency_into_the_budget_currency(): void
    {
        config(['ai-finops.currency.base' => 'USD']);
        config(['ai-finops.currency.fx_provider' => fn (string $from, string $to) => 0.5]); // 1 USD = 0.5 EUR

        $this->ledger('dgr_test', 30.0); // 30 USD → 15 EUR

        $under = $this->guard()->verdict($this->grant(new DelegationBudget(amount: 20.0, currency: 'EUR')));
        $this->assertTrue($under->allowed);
        $this->assertEqualsWithDelta(5.0, $under->remaining['amount'], 1e-6);

        $this->ledger('dgr_test', 10.0); // total 40 USD → 20 EUR
        $at = $this->guard()->verdict($this->grant(new DelegationBudget(amount: 20.0, currency: 'EUR')));
        $this->assertFalse($at->allowed);
        $this->assertSame('amount 20.00/20.00 EUR', $at->reason);
    }

    public function test_only_the_grants_own_rows_count(): void
    {
        $this->ledger('dgr_other', 999.0);
        $this->ledger('dgr_test', 1.0);

        $verdict = $this->guard()->verdict($this->grant(new DelegationBudget(amount: 5.0, currency: 'USD')));

        $this->assertTrue($verdict->allowed);
    }

    public function test_trace_context_stamps_the_grant_id_onto_recorded_envelopes(): void
    {
        /** @var TraceContext $trace */
        $trace = $this->app->make(TraceContext::class);

        $row = $trace->within(['delegation_grant_id' => 'dgr_ctx'], function (): array {
            /** @var TraceContext $inner */
            $inner = $this->app->make(TraceContext::class);
            $this->assertSame('dgr_ctx', $inner->delegationGrantId());

            return (new AiCallEnvelope('t', 'openai', 'gpt-5.1', delegationGrantId: $inner->delegationGrantId()))
                ->toLedgerRow();
        });

        $this->assertSame('dgr_ctx', $row['delegation_grant_id']);
        $this->assertNull($trace->delegationGrantId()); // scope restored
    }

    public function test_container_binding_is_gated_on_the_integration_toggle(): void
    {
        // Default: toggle off → the contract is NOT bound (iam-agents then refuses
        // budgeted grants as unenforceable — fail-closed on their side).
        $this->assertFalse($this->app->bound(DelegationBudgetGuard::class));

        // Explicit opt-in → the ledger guard is the bound implementation.
        config(['ai-finops.integrations.iam_delegation.enabled' => true]);
        (new LaravelAiFinOpsServiceProvider($this->app))->register();

        $this->assertInstanceOf(
            LedgerDelegationBudgetGuard::class,
            $this->app->make(DelegationBudgetGuard::class),
        );
    }
}
