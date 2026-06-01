<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Padosoft\LaravelAiFinOps\Pricing\Cost\FalUnitCostResolver;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class FalUnitCostTest extends TestCase
{
    use RefreshDatabase;

    private function envelope(string $model, array $metadata): AiCallEnvelope
    {
        return new AiCallEnvelope(traceId: 't', provider: 'fal', model: $model, metadata: $metadata);
    }

    public function test_per_second_unit_cost_from_inference_time(): void
    {
        PricingOverride::create([
            'model' => 'flux-video', 'provider' => 'fal',
            'input_cost_per_token' => 0, 'output_cost_per_token' => 0,
            'unit' => 'per_second', 'unit_rate' => 0.0005, 'currency' => 'USD',
        ]);

        $resolved = (new FalUnitCostResolver)->resolve($this->envelope('flux-video', ['inference_time' => 8.0]));

        $this->assertNotNull($resolved);
        $this->assertEqualsWithDelta(0.004, $resolved->amount, 1e-9); // 8s × 0.0005
        $this->assertSame('fal', $resolved->source);
        $this->assertNull($resolved->tokens);
    }

    public function test_per_image_unit_cost_from_output_count(): void
    {
        PricingOverride::create([
            'model' => 'flux-image', 'provider' => 'fal',
            'input_cost_per_token' => 0, 'output_cost_per_token' => 0,
            'unit' => 'per_image', 'unit_rate' => 0.003, 'currency' => 'USD',
        ]);

        $resolved = (new FalUnitCostResolver)->resolve($this->envelope('flux-image', ['image_count' => 4]));

        $this->assertEqualsWithDelta(0.012, $resolved->amount, 1e-9); // 4 × 0.003
    }

    public function test_returns_null_without_unit_override(): void
    {
        $this->assertNull((new FalUnitCostResolver)->resolve($this->envelope('no-rate', ['inference_time' => 5])));
    }

    public function test_returns_null_without_quantity(): void
    {
        PricingOverride::create([
            'model' => 'flux-video', 'provider' => 'fal',
            'input_cost_per_token' => 0, 'output_cost_per_token' => 0,
            'unit' => 'per_second', 'unit_rate' => 0.0005, 'currency' => 'USD',
        ]);

        // No inference_time in metadata → cannot price → null (cascade falls back).
        $this->assertNull((new FalUnitCostResolver)->resolve($this->envelope('flux-video', [])));
    }
}
