<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Padosoft\LaravelAiFinOps\Metering\MeteringListener;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class MeteringHookTest extends TestCase
{
    use RefreshDatabase;

    public function test_metering_listeners_are_registered_for_laravel_ai_events(): void
    {
        $this->assertTrue(Event::hasListeners(AgentPrompted::class));
        $this->assertTrue(Event::hasListeners(EmbeddingsGenerated::class));
    }

    public function test_agent_response_is_metered_to_the_ledger(): void
    {
        $listener = $this->app->make(MeteringListener::class);

        $response = new AgentResponse(
            'inv-1',
            'Hello world',
            new Usage(promptTokens: 150, completionTokens: 60, cacheReadInputTokens: 20, reasoningTokens: 5),
            new Meta(provider: 'openai', model: 'gpt-5.1'),
        );

        $listener->recordAgentResponse('inv-1', $response);

        $row = UsageRecord::query()->where('trace_id', 'inv-1')->firstOrFail();

        $this->assertSame('openai', $row->provider);
        $this->assertSame('gpt-5.1', $row->model);
        $this->assertSame('text', $row->modality);
        $this->assertSame('recorded', $row->status);
        $this->assertSame(150, $row->tokens_input);
        $this->assertSame(60, $row->tokens_output);
        $this->assertSame(20, $row->tokens_cached);
        $this->assertSame(5, $row->tokens_reasoning);
    }

    public function test_embeddings_response_is_metered_to_the_ledger(): void
    {
        $listener = $this->app->make(MeteringListener::class);

        $response = new EmbeddingsResponse([[0.1, 0.2]], 320, new Meta(provider: 'openai', model: 'text-embedding-3-large'));

        $listener->recordEmbeddings('inv-2', $response, 'text-embedding-3-large');

        $row = UsageRecord::query()->where('trace_id', 'inv-2')->firstOrFail();

        $this->assertSame('embedding', $row->modality);
        $this->assertSame('text-embedding-3-large', $row->model);
        $this->assertSame(320, $row->tokens_input);
        $this->assertSame(0, $row->tokens_output);
    }

    public function test_embeddings_without_provider_meta_falls_back_to_unknown(): void
    {
        $listener = $this->app->make(MeteringListener::class);

        $response = new EmbeddingsResponse([[0.1]], 10, new Meta(model: 'text-embedding-3-large'));
        $listener->recordEmbeddings('inv-emb', $response, 'text-embedding-3-large');

        $row = UsageRecord::query()->where('trace_id', 'inv-emb')->firstOrFail();

        $this->assertSame('unknown', $row->provider);
        $this->assertSame('text-embedding-3-large', $row->model);
    }

    public function test_metering_no_ops_when_disabled(): void
    {
        config(['ai-finops.metering' => false]);

        $listener = $this->app->make(MeteringListener::class);
        $listener->recordAgentResponse('inv-3', new AgentResponse(
            'inv-3', 'x', new Usage(promptTokens: 1), new Meta(provider: 'openai', model: 'gpt-5.1'),
        ));

        $this->assertSame(0, UsageRecord::query()->where('trace_id', 'inv-3')->count());
    }

    public function test_tenant_resolver_populates_tenant_id(): void
    {
        config([
            'ai-finops.tenancy.enabled' => true,
            'ai-finops.tenancy.resolver' => fn () => 'tenant-xyz',
        ]);

        $listener = $this->app->make(MeteringListener::class);
        $listener->recordAgentResponse('inv-4', new AgentResponse(
            'inv-4', 'x', new Usage(promptTokens: 10, completionTokens: 5), new Meta(provider: 'anthropic', model: 'claude-haiku-4.5'),
        ));

        $this->assertSame('tenant-xyz', UsageRecord::query()->where('trace_id', 'inv-4')->value('tenant_id'));
    }
}
