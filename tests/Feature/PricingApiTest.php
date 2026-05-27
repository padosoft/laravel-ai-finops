<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class PricingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(PricingSource::class, fn () => new ArrayPricingSource([
            'gpt-5.1' => ['input_cost_per_token' => 0.000002, 'output_cost_per_token' => 0.000008, 'litellm_provider' => 'openai'],
            'claude-haiku-4.5' => ['input_cost_per_token' => 0.000001, 'output_cost_per_token' => 0.000005, 'litellm_provider' => 'anthropic'],
        ]));
    }

    public function test_models_lists_and_searches(): void
    {
        $this->getJson('/api/ai-finops/pricing/models')
            ->assertOk()
            ->assertJsonPath('count', 2);

        $this->getJson('/api/ai-finops/pricing/models?search=claude')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.model', 'claude-haiku-4.5');
    }

    public function test_sync_reports_model_count_and_timestamp(): void
    {
        $this->postJson('/api/ai-finops/pricing/sync')
            ->assertOk()
            ->assertJsonPath('synced', true)
            ->assertJsonPath('models', 2);

        $this->getJson('/api/ai-finops/pricing/sync/status')
            ->assertOk()
            ->assertJsonPath('models', 2);
    }

    public function test_override_crud(): void
    {
        $this->postJson('/api/ai-finops/pricing/overrides', [
            'model' => 'gpt-5.1',
            'input_cost_per_token' => 0.0000015,
            'output_cost_per_token' => 0.000006,
        ])->assertCreated();

        $id = PricingOverride::query()->firstOrFail()->id;

        $this->putJson("/api/ai-finops/pricing/overrides/{$id}", [
            'model' => 'gpt-5.1',
            'input_cost_per_token' => 0.0000011,
            'output_cost_per_token' => 0.000006,
        ])->assertOk();

        $this->getJson('/api/ai-finops/pricing/overrides')
            ->assertOk()
            ->assertJsonPath('data.0.input_cost_per_token', 0.0000011);

        $this->deleteJson("/api/ai-finops/pricing/overrides/{$id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSame(0, PricingOverride::query()->count());
    }

    public function test_override_validation_rejects_missing_fields(): void
    {
        $this->postJson('/api/ai-finops/pricing/overrides', ['model' => 'x'])
            ->assertStatus(422);
    }
}
