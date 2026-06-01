<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\CostMethod;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class LedgerCostMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_envelope_persists_cost_method_estimated_and_billed(): void
    {
        $envelope = new AiCallEnvelope(
            traceId: 'm9-1',
            provider: 'openrouter',
            model: 'meta-llama/llama-3.3-70b-instruct',
            tokens: new TokenUsage(input: 100, output: 50),
            cost: new CostBreakdown(total: 0.0123, currency: 'USD'),
            costMethod: CostMethod::Actual,
            tokensEstimated: false,
            billedCost: 0.0123,
            billedCurrency: 'USD',
        );

        UsageRecord::create($envelope->toLedgerRow());
        $row = UsageRecord::query()->where('trace_id', 'm9-1')->firstOrFail();

        $this->assertSame('actual', $row->cost_method);
        $this->assertFalse($row->tokens_estimated);
        $this->assertSame('0.01230000', (string) $row->billed_cost);
        $this->assertSame('USD', $row->billed_currency);
    }

    public function test_estimated_flag_round_trips(): void
    {
        $envelope = new AiCallEnvelope(
            traceId: 'm9-2',
            provider: 'unknown',
            model: 'mystery',
            tokens: new TokenUsage(input: 20),
            cost: new CostBreakdown(total: 0.001, currency: 'USD'),
            costMethod: CostMethod::Estimated,
            tokensEstimated: true,
        );

        UsageRecord::create($envelope->toLedgerRow());
        $row = UsageRecord::query()->where('trace_id', 'm9-2')->firstOrFail();

        $this->assertSame('estimated', $row->cost_method);
        $this->assertTrue($row->tokens_estimated);
        $this->assertNull($row->billed_cost);

        // fromArray reconstructs the enum + flags.
        $rebuilt = AiCallEnvelope::fromArray($row->toArray());
        $this->assertSame(CostMethod::Estimated, $rebuilt->costMethod);
        $this->assertTrue($rebuilt->tokensEstimated);
    }
}
