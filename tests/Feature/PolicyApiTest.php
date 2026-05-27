<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Models\Policy;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class PolicyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_and_validation(): void
    {
        $this->postJson('/api/ai-finops/policies', [
            'name' => 'Block expensive', 'scope_type' => 'global', 'min_cost' => 5, 'action' => 'block',
        ])->assertCreated();

        // downgrade requires action_param
        $this->postJson('/api/ai-finops/policies', [
            'name' => 'bad', 'scope_type' => 'global', 'action' => 'downgrade',
        ])->assertStatus(422);

        $this->postJson('/api/ai-finops/policies/validate', [
            'name' => 'ok', 'scope_type' => 'tenant', 'scope_id' => 'acme', 'action' => 'throttle',
        ])->assertOk()->assertJsonPath('valid', true);

        $id = Policy::query()->firstOrFail()->id;
        $this->getJson('/api/ai-finops/policies')->assertOk()->assertJsonPath('data.0.name', 'Block expensive');
        $this->deleteJson("/api/ai-finops/policies/{$id}")->assertOk();
    }

    public function test_simulate_reports_match_and_action(): void
    {
        $policy = Policy::create([
            'name' => 'free tier downgrade', 'scope_type' => 'tenant', 'scope_id' => 'free',
            'action' => 'downgrade', 'action_param' => 'gpt-5.1-mini',
        ]);

        $this->postJson("/api/ai-finops/policies/{$policy->id}/simulate", [
            'provider' => 'openai', 'model' => 'gpt-5.1', 'tenant_id' => 'free',
        ])->assertOk()->assertJsonPath('matches', true)->assertJsonPath('action', 'downgrade');

        $this->postJson("/api/ai-finops/policies/{$policy->id}/simulate", [
            'provider' => 'openai', 'model' => 'gpt-5.1', 'tenant_id' => 'paid',
        ])->assertOk()->assertJsonPath('matches', false);
    }
}
