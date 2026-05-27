<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Models\CostCenter;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class ChargebackApiTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(?string $costCenter, float $cost): void
    {
        UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: uniqid('t', true), provider: 'openai', model: 'gpt-5.1',
            tokens: new TokenUsage(input: 10, output: 5), cost: new CostBreakdown(total: $cost, currency: 'USD'),
            costCenter: $costCenter,
        ))->save();
    }

    public function test_cost_center_crud(): void
    {
        $this->postJson('/api/ai-finops/cost-centers', ['code' => 'rnd', 'name' => 'R&D'])->assertCreated();

        $id = CostCenter::query()->firstOrFail()->id;

        $this->postJson('/api/ai-finops/cost-centers', ['code' => 'rnd', 'name' => 'dup'])->assertStatus(422);

        $this->getJson('/api/ai-finops/cost-centers')->assertOk()->assertJsonPath('data.0.code', 'rnd');

        $this->deleteJson("/api/ai-finops/cost-centers/{$id}")->assertOk();
    }

    public function test_chargeback_report_allocates_spend(): void
    {
        CostCenter::create(['code' => 'rnd', 'name' => 'R&D']);
        $this->ledger('rnd', 5.0);
        $this->ledger('rnd', 3.0);
        $this->ledger(null, 2.0);

        $res = $this->getJson('/api/ai-finops/chargeback/report')->assertOk();
        $this->assertEquals(10.0, $res->json('total'));

        $data = collect($res->json('data'));
        $rnd = $data->firstWhere('cost_center', 'rnd');
        $unalloc = $data->firstWhere('cost_center', 'unallocated');

        $this->assertEquals(8.0, $rnd['cost']);
        $this->assertSame('R&D', $rnd['name']);
        $this->assertEquals(2.0, $unalloc['cost']);
    }
}
