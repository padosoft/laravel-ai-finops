<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Unit;

use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\CallStatus;
use Padosoft\LaravelAiFinOps\Enums\Modality;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class AiCallEnvelopeTest extends TestCase
{
    public function test_token_usage_total_sums_input_and_output_only(): void
    {
        $tokens = new TokenUsage(input: 100, output: 40, cached: 30, reasoning: 10);

        $this->assertSame(140, $tokens->total());
    }

    public function test_cost_breakdown_zero_factory(): void
    {
        $cost = CostBreakdown::zero('EUR');

        $this->assertSame(0.0, $cost->total);
        $this->assertSame('EUR', $cost->currency);
    }

    public function test_with_helpers_return_new_immutable_instances(): void
    {
        $envelope = new AiCallEnvelope(traceId: 't-1', provider: 'openai', model: 'gpt-5.1');

        $recorded = $envelope
            ->withStatus(CallStatus::Recorded)
            ->withTokens(new TokenUsage(input: 10, output: 5))
            ->withCost(new CostBreakdown(total: 0.0021, currency: 'USD'))
            ->withLatency(1234)
            ->withMetadata(['region' => 'eu']);

        // original untouched
        $this->assertSame(CallStatus::Estimated, $envelope->status);
        $this->assertSame(0, $envelope->tokens->total());

        // new instance carries the changes
        $this->assertSame(CallStatus::Recorded, $recorded->status);
        $this->assertSame(15, $recorded->tokens->total());
        $this->assertSame(1234, $recorded->latencyMs);
        $this->assertSame(['region' => 'eu'], $recorded->metadata);
    }

    public function test_to_ledger_row_and_from_array_roundtrip(): void
    {
        $envelope = new AiCallEnvelope(
            traceId: 'trace-42',
            provider: 'anthropic',
            model: 'claude-haiku-4.5',
            modality: Modality::Text,
            status: CallStatus::Recorded,
            tokens: new TokenUsage(input: 200, output: 80, cached: 50),
            cost: new CostBreakdown(total: 0.0123, input: 0.008, output: 0.0043, currency: 'USD'),
            tenantId: 7,
            costCenter: 'rnd',
            agentStep: 'summarize',
            purposeTag: 'support-bot',
            latencyMs: 980,
            metadata: ['k' => 'v'],
        );

        $rebuilt = AiCallEnvelope::fromArray($envelope->toLedgerRow());

        $this->assertSame('trace-42', $rebuilt->traceId);
        $this->assertSame('anthropic', $rebuilt->provider);
        $this->assertSame('claude-haiku-4.5', $rebuilt->model);
        $this->assertSame(CallStatus::Recorded, $rebuilt->status);
        $this->assertSame(280, $rebuilt->tokens->total());
        $this->assertSame(50, $rebuilt->tokens->cached);
        $this->assertSame(0.0123, $rebuilt->cost->total);
        $this->assertSame('7', $rebuilt->tenantId);
        $this->assertSame('summarize', $rebuilt->agentStep);
        $this->assertSame(['k' => 'v'], $rebuilt->metadata);
    }
}
