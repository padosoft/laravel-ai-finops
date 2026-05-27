<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class ForecastApiTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(float $cost, ?string $createdAt = null): void
    {
        $row = UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: uniqid('t', true), provider: 'openai', model: 'gpt-5.1',
            tokens: new TokenUsage, cost: new CostBreakdown(total: $cost, currency: 'USD'),
        ));
        $row->save();

        if ($createdAt !== null) {
            $row->forceFill(['created_at' => $createdAt])->save();
        }
    }

    public function test_month_to_date_projects_spend(): void
    {
        $this->ledger(5.0);

        $this->getJson('/api/ai-finops/forecast')
            ->assertOk()
            ->assertJsonStructure(['spent', 'projected', 'days_elapsed', 'days_in_period']);
    }

    public function test_budget_forecast_flags_exceed(): void
    {
        $budget = Budget::create([
            'name' => 'Rolling', 'scope_type' => 'global', 'limit_amount' => 1.0,
            'period' => 'rolling', 'rolling_days' => 2,
        ]);
        $this->ledger(5.0); // within the rolling window (now)

        $this->getJson("/api/ai-finops/forecast/{$budget->id}")
            ->assertOk()
            ->assertJsonPath('will_exceed', true)
            ->assertJsonPath('budget_id', $budget->id);
    }

    public function test_anomaly_detection_flags_spike_and_ack(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->ledger(0.01, now()->subDays($i)->toDateTimeString());
        }
        $this->ledger(5.0, now()->toDateTimeString()); // today's spike

        $res = $this->getJson('/api/ai-finops/anomalies')->assertOk();
        $data = collect($res->json('data'));
        $this->assertGreaterThanOrEqual(1, $data->count());

        $spikeDay = now()->toDateString();
        $this->assertTrue($data->contains(fn ($a) => $a['day'] === $spikeDay));

        $this->postJson('/api/ai-finops/anomalies/ack', ['day' => $spikeDay, 'acked_by' => 'ops'])
            ->assertCreated();

        $after = collect($this->getJson('/api/ai-finops/anomalies')->json('data'));
        $this->assertTrue($after->firstWhere('day', $spikeDay)['acked']);
    }
}
