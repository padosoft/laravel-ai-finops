<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class BudgetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_flow(): void
    {
        $this->postJson('/api/ai-finops/budgets', [
            'name' => 'Acme', 'scope_type' => 'tenant', 'scope_id' => 'acme',
            'limit_amount' => 50, 'period' => 'monthly', 'soft_limit_pct' => 80,
        ])->assertCreated();

        $id = Budget::query()->firstOrFail()->id;

        $this->getJson('/api/ai-finops/budgets')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Acme')
            ->assertJsonPath('data.0.state', 'ok');

        $this->getJson("/api/ai-finops/budgets/{$id}")
            ->assertOk()
            ->assertJsonPath('status.limit', 50);

        $this->putJson("/api/ai-finops/budgets/{$id}", [
            'name' => 'Acme', 'scope_type' => 'tenant', 'scope_id' => 'acme',
            'limit_amount' => 75, 'period' => 'monthly',
        ])->assertOk();

        $this->deleteJson("/api/ai-finops/budgets/{$id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);
    }

    public function test_scope_id_required_unless_global(): void
    {
        $this->postJson('/api/ai-finops/budgets', [
            'name' => 'x', 'scope_type' => 'tenant', 'limit_amount' => 10, 'period' => 'monthly',
        ])->assertStatus(422);

        $this->postJson('/api/ai-finops/budgets', [
            'name' => 'Global', 'scope_type' => 'global', 'limit_amount' => 1000, 'period' => 'monthly',
        ])->assertCreated();
    }

    public function test_tree_nests_children(): void
    {
        $parent = Budget::create(['name' => 'Org', 'scope_type' => 'global', 'limit_amount' => 1000, 'period' => 'monthly']);
        Budget::create(['name' => 'Team', 'parent_id' => $parent->id, 'scope_type' => 'cost_center', 'scope_id' => 'team-a', 'limit_amount' => 100, 'period' => 'monthly']);

        $this->getJson('/api/ai-finops/budgets/tree')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Org')
            ->assertJsonPath('data.0.children.0.name', 'Team');
    }

    public function test_burndown_returns_series(): void
    {
        $b = Budget::create(['name' => 'g', 'scope_type' => 'global', 'limit_amount' => 100, 'period' => 'monthly']);

        $this->getJson("/api/ai-finops/budgets/{$b->id}/burndown")
            ->assertOk()
            ->assertJsonPath('budget_id', $b->id)
            ->assertJsonStructure(['limit', 'period_start', 'series']);
    }
}
