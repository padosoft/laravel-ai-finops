<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Contracts\CopilotProvider;
use Padosoft\LaravelAiFinOps\Models\CopilotQuery;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class CopilotApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_returns_not_configured_by_default_and_logs_history(): void
    {
        $this->postJson('/api/ai-finops/copilot/query', ['question' => 'How much did I spend on embeddings?'])
            ->assertOk()
            ->assertJsonPath('configured', false);

        $this->assertSame(1, CopilotQuery::query()->count());

        $this->getJson('/api/ai-finops/copilot/history')
            ->assertOk()
            ->assertJsonPath('data.0.question', 'How much did I spend on embeddings?');
    }

    public function test_bound_provider_answers(): void
    {
        $this->app->singleton(CopilotProvider::class, fn () => new class implements CopilotProvider
        {
            public function answer(string $question, array $context = []): array
            {
                return ['answer' => 'You spent $12.34.', 'configured' => true];
            }
        });

        $this->postJson('/api/ai-finops/copilot/query', ['question' => 'spend?'])
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('answer', 'You spent $12.34.');
    }
}
