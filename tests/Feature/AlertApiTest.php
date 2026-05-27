<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Padosoft\LaravelAiFinOps\Events\AlertChannelTested;
use Padosoft\LaravelAiFinOps\Models\AlertChannel;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class AlertApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_crud_never_leaks_config(): void
    {
        $res = $this->postJson('/api/ai-finops/alerts/channels', [
            'type' => 'webhook', 'name' => 'Ops', 'config' => ['url' => 'https://hooks.example/secret'],
        ])->assertCreated();

        $res->assertJsonPath('has_config', true);
        $res->assertJsonMissingPath('config');

        $this->getJson('/api/ai-finops/alerts/channels')
            ->assertOk()
            ->assertJsonPath('data.0.has_config', true)
            ->assertJsonMissingPath('data.0.config');

        // The secret is persisted but never serialized.
        $this->assertSame('https://hooks.example/secret', AlertChannel::query()->firstOrFail()->config['url']);
    }

    public function test_rule_crud_and_log(): void
    {
        $budget = Budget::create(['name' => 'g', 'scope_type' => 'global', 'limit_amount' => 100, 'period' => 'monthly']);

        $this->postJson('/api/ai-finops/alerts/rules', [
            'name' => '80%', 'budget_id' => $budget->id, 'threshold_pct' => 80,
        ])->assertCreated();

        $this->postJson('/api/ai-finops/alerts/rules', [
            'name' => 'bad', 'budget_id' => 9999, 'threshold_pct' => 80,
        ])->assertStatus(422);

        $this->getJson('/api/ai-finops/alerts/rules')->assertOk()->assertJsonPath('data.0.threshold_pct', 80);
        $this->getJson('/api/ai-finops/alerts/log')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_channel_test_fires_event(): void
    {
        Event::fake([AlertChannelTested::class]);

        $channel = AlertChannel::create(['type' => 'mail', 'name' => 'Ops']);

        $this->postJson("/api/ai-finops/alerts/channels/{$channel->id}/test")
            ->assertOk()
            ->assertJsonPath('tested', true);

        Event::assertDispatched(AlertChannelTested::class);
    }
}
