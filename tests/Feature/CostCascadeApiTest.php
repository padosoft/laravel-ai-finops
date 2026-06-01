<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class CostCascadeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(PricingSource::class, fn () => new ArrayPricingSource([
            'gpt-5.1' => ['input_cost_per_token' => 0.000002, 'output_cost_per_token' => 0.000008, 'litellm_provider' => 'openai'],
        ]));
    }

    public function test_estimate_from_prompt_text_returns_estimated_method(): void
    {
        $this->postJson('/api/ai-finops/diagnostics/estimate', [
            'provider' => 'openai',
            'model' => 'gpt-5.1',
            'prompt' => 'Summarize the quarterly financial report in three bullet points.',
        ])
            ->assertOk()
            ->assertJsonPath('method', 'estimated')
            ->assertJsonPath('tokens_estimated', true);
    }

    public function test_estimate_with_explicit_tokens_is_computed(): void
    {
        $this->postJson('/api/ai-finops/diagnostics/estimate', [
            'provider' => 'openai',
            'model' => 'gpt-5.1',
            'tokens_input' => 1000,
            'tokens_output' => 500,
        ])
            ->assertOk()
            ->assertJsonPath('method', 'computed')
            ->assertJsonPath('tokens_estimated', false);
    }

    public function test_settings_snapshot_exposes_estimator_and_actual_cost(): void
    {
        $this->getJson('/api/ai-finops/settings')
            ->assertOk()
            ->assertJsonPath('pricing.token_estimator', 'heuristic')
            ->assertJsonPath('pricing.actual_cost_enabled', false)
            ->assertJsonPath('pricing.has_openrouter_key', false);
    }

    public function test_usage_rows_expose_cost_method_and_billed_cost(): void
    {
        UsageRecord::create([
            'trace_id' => 'u1', 'provider' => 'openrouter', 'model' => 'x',
            'tokens_input' => 100, 'tokens_output' => 50,
            'cost_total' => 0.0042, 'currency' => 'USD',
            'cost_method' => 'actual', 'tokens_estimated' => false,
            'billed_cost' => 0.0042, 'billed_currency' => 'USD',
        ]);

        $this->getJson('/api/ai-finops/usage')
            ->assertOk()
            ->assertJsonPath('data.0.cost_method', 'actual')
            ->assertJsonPath('data.0.tokens_estimated', false)
            ->assertJsonPath('data.0.billed_cost', '0.00420000');
    }
}
