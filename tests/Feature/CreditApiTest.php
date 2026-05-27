<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Models\CreditPool;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class CreditApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pool_crud_topup_and_ledger(): void
    {
        $this->postJson('/api/ai-finops/credits/pools', [
            'name' => 'Team A', 'scope_type' => 'cost_center', 'scope_id' => 'team-a', 'balance' => 10,
        ])->assertCreated();

        $id = CreditPool::query()->firstOrFail()->id;

        $this->postJson("/api/ai-finops/credits/pools/{$id}/topup", ['amount' => 5, 'reason' => 'monthly grant'])
            ->assertOk()
            ->assertJsonPath('balance', 15);

        $this->getJson("/api/ai-finops/credits/pools/{$id}/ledger")
            ->assertOk()
            ->assertJsonPath('balance', 15)
            ->assertJsonPath('transactions.0.type', 'topup');

        $this->postJson("/api/ai-finops/credits/pools/{$id}/topup", ['amount' => -1])->assertStatus(422);
    }
}
