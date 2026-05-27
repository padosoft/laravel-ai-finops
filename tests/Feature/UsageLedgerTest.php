<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\CallStatus;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class UsageLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_creates_the_prefixed_ledger_table(): void
    {
        $this->assertTrue(Schema::hasTable('ai_finops_usage_ledger'));
        $this->assertTrue(Schema::hasColumns('ai_finops_usage_ledger', [
            'trace_id', 'provider', 'model', 'cost_total', 'currency', 'tokens_input', 'metadata',
        ]));
    }

    public function test_usage_record_persists_from_envelope(): void
    {
        $envelope = new AiCallEnvelope(
            traceId: 'trace-99',
            provider: 'openai',
            model: 'gpt-5.1-mini',
            status: CallStatus::Recorded,
            tokens: new TokenUsage(input: 120, output: 60),
            cost: new CostBreakdown(total: 0.0042, input: 0.003, output: 0.0012, currency: 'USD'),
            tenantId: 'acme',
            purposeTag: 'rag-answer',
        );

        UsageRecord::fromEnvelope($envelope)->save();

        $row = UsageRecord::query()->where('trace_id', 'trace-99')->firstOrFail();

        $this->assertSame('openai', $row->provider);
        $this->assertSame('gpt-5.1-mini', $row->model);
        $this->assertSame('recorded', $row->status);
        $this->assertSame(120, $row->tokens_input);
        $this->assertSame('0.00420000', (string) $row->cost_total);
        $this->assertSame('acme', $row->tenant_id);
        $this->assertSame('rag-answer', $row->purpose_tag);
    }

    public function test_ledger_is_immutable_no_updated_at(): void
    {
        $this->assertNull(UsageRecord::UPDATED_AT);
    }
}
