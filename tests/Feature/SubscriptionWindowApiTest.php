<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Models\SubscriptionWindow;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class SubscriptionWindowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_round_trip(): void
    {
        $this->postJson('/api/ai-finops/pricing/subscription-windows', [
            'provider' => 'anthropic',
            'label' => 'claude-max',
            'starts_at' => now()->subDay()->toDateTimeString(),
            'ends_at' => null,
            'enabled' => true,
            'note' => 'team plan',
        ])->assertCreated()
            ->assertJsonPath('provider', 'anthropic')
            ->assertJsonPath('label', 'claude-max');

        $id = SubscriptionWindow::query()->firstOrFail()->id;

        $this->putJson("/api/ai-finops/pricing/subscription-windows/{$id}", [
            'provider' => 'anthropic',
            'label' => 'claude-max',
            'ends_at' => now()->toDateTimeString(), // operator shortens on exhaustion
        ])->assertOk();

        $this->getJson('/api/ai-finops/pricing/subscription-windows')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'claude-max');

        $this->deleteJson("/api/ai-finops/pricing/subscription-windows/{$id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSame(0, SubscriptionWindow::query()->count());
    }

    public function test_validation_requires_provider_and_label(): void
    {
        $this->postJson('/api/ai-finops/pricing/subscription-windows', ['provider' => 'anthropic'])
            ->assertStatus(422);
    }
}
