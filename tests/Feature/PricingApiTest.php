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

    public function test_sync_status_reports_per_source_and_has_key_boolean(): void
    {
        config(['ai-finops.pricing.openrouter.key' => 'sk-secret']);

        $response = $this->getJson('/api/ai-finops/pricing/sync/status')
            ->assertOk()
            ->assertJsonPath('has_openrouter_key', true)
            ->assertJsonPath('sources.0.name', 'litellm');

        // The key value must never leak into the payload.
        $this->assertStringNotContainsString('sk-secret', $response->getContent());
    }

    public function test_models_carry_source_field_and_filter(): void
    {
        $this->getJson('/api/ai-finops/pricing/models')
            ->assertOk()
            ->assertJsonPath('data.0.source', 'litellm');

        $this->getJson('/api/ai-finops/pricing/models?source=openrouter')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_override_accepts_fal_unit_rate(): void
    {
        $this->postJson('/api/ai-finops/pricing/overrides', [
            'model' => 'flux-video',
            'provider' => 'fal',
            'input_cost_per_token' => 0,
            'output_cost_per_token' => 0,
            'unit' => 'per_second',
            'unit_rate' => 0.0005,
            'currency' => 'USD',
        ])->assertCreated();

        $row = PricingOverride::query()->where('model', 'flux-video')->firstOrFail();
        $this->assertSame('per_second', $row->unit);
        $this->assertEqualsWithDelta(0.0005, (float) $row->unit_rate, 1e-12);
    }

    public function test_override_accepts_per_million_unit(): void
    {
        $this->postJson('/api/ai-finops/pricing/overrides', [
            'model' => 'Llama-3.3-70B-Instruct',
            'provider' => 'regolo',
            'input_cost_per_token' => 0.60,
            'output_cost_per_token' => 2.70,
            'unit' => 'per_million',
            'currency' => 'EUR',
        ])->assertCreated();

        $row = PricingOverride::query()->firstOrFail();
        $this->assertSame('per_million', $row->unit);
        $this->assertEqualsWithDelta(0.60 / 1_000_000, $row->toModelPrice()->inputPerToken, 1e-15);
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
