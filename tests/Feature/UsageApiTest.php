<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\CallStatus;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class UsageApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedRecord(string $trace, string $provider, string $model, float $cost, ?string $step = null): UsageRecord
    {
        $record = UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: $trace,
            provider: $provider,
            model: $model,
            status: CallStatus::Recorded,
            tokens: new TokenUsage(input: 100, output: 50),
            cost: new CostBreakdown(total: $cost, currency: 'USD'),
            agentStep: $step,
        ));
        $record->save();

        return $record;
    }

    public function test_index_lists_and_filters_usage(): void
    {
        $this->seedRecord('t1', 'openai', 'gpt-5.1', 0.01);
        $this->seedRecord('t2', 'anthropic', 'claude-haiku-4.5', 0.02);

        $this->getJson('/api/ai-finops/usage')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'total']);

        $this->getJson('/api/ai-finops/usage?provider=anthropic')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.model', 'claude-haiku-4.5');
    }

    public function test_index_filters_by_delegation_grant(): void
    {
        UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: 'td1', provider: 'openai', model: 'gpt-5.1',
            cost: new CostBreakdown(total: 0.01, currency: 'USD'),
            delegationGrantId: 'dgr_filter',
        ))->save();
        $this->seedRecord('td2', 'openai', 'gpt-5.1', 0.02); // non-delegated

        $this->getJson('/api/ai-finops/usage?delegation_grant_id=dgr_filter')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.delegation_grant_id', 'dgr_filter');
    }

    public function test_show_returns_single_record(): void
    {
        $row = $this->seedRecord('t3', 'openai', 'gpt-5.1', 0.03);

        $this->getJson("/api/ai-finops/usage/{$row->id}")
            ->assertOk()
            ->assertJsonPath('trace_id', 't3');
    }

    public function test_trace_aggregates_spans_and_totals(): void
    {
        $this->seedRecord('trace-x', 'openai', 'gpt-5.1', 0.01, 'plan');
        $this->seedRecord('trace-x', 'openai', 'gpt-5.1-mini', 0.02, 'answer');

        $this->getJson('/api/ai-finops/usage/trace-x/trace')
            ->assertOk()
            ->assertJsonPath('trace_id', 'trace-x')
            ->assertJsonPath('totals.calls', 2)
            ->assertJsonPath('totals.cost_total', 0.03);
    }
}
