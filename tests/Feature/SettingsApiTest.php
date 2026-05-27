<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(PricingSource::class, fn () => new ArrayPricingSource([
            'gpt-5.1' => ['input_cost_per_token' => 0.000002, 'output_cost_per_token' => 0.000008, 'litellm_provider' => 'openai'],
        ]));
    }

    public function test_settings_index_returns_effective_config(): void
    {
        $this->getJson('/api/ai-finops/settings')
            ->assertOk()
            ->assertJsonPath('enforcement', true)
            ->assertJsonPath('block_status', 402);
    }

    public function test_kill_switch_set_and_list(): void
    {
        $this->postJson('/api/ai-finops/settings/kill-switch', [
            'scope_type' => 'provider', 'scope_id' => 'openai', 'active' => true, 'reason' => 'incident',
        ])->assertCreated();

        $this->getJson('/api/ai-finops/settings/kill-switch')
            ->assertOk()
            ->assertJsonPath('data.0.scope_id', 'openai');
    }

    public function test_estimate_returns_cost_and_allow_decision(): void
    {
        $this->postJson('/api/ai-finops/diagnostics/estimate', [
            'provider' => 'openai', 'model' => 'gpt-5.1', 'tokens_input' => 1000, 'tokens_output' => 500,
        ])
            ->assertOk()
            ->assertJsonPath('cost.total', 0.006)
            ->assertJsonPath('decision.action', 'allow');
    }

    public function test_estimate_reflects_kill_switch_block(): void
    {
        config(['ai-finops.kill_switch' => true]);

        $this->postJson('/api/ai-finops/diagnostics/estimate', [
            'provider' => 'openai', 'model' => 'gpt-5.1', 'tokens_input' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('decision.action', 'block');
    }
}
