<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Padosoft\LaravelAiFinOps\Pricing\OpenRouterPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class OpenRouterPricingSourceTest extends TestCase
{
    private function source(Http $http): OpenRouterPricingSource
    {
        return new OpenRouterPricingSource($http, $this->app->make(Cache::class), $this->app['config']);
    }

    public function test_normalizes_models_to_litellm_attr_map(): void
    {
        config(['ai-finops.pricing.openrouter.enabled' => true]);
        config(['ai-finops.pricing.openrouter.allow_keyless' => true]);

        $http = $this->app->make(Http::class);
        $http->fake([
            '*' => $http->response(['data' => [[
                'id' => 'meta-llama/llama-3.3-70b-instruct',
                'top_provider' => ['provider' => 'deepinfra'],
                'pricing' => [
                    'prompt' => '0.0000001',
                    'completion' => '0.00000032',
                    'input_cache_read' => '0.00000004',
                ],
            ]]]),
        ]);

        $source = $this->source($http);

        $this->assertSame(1, $source->sync());
        $this->assertSame('openrouter', $source->name());

        $all = $source->all();
        $this->assertArrayHasKey('meta-llama/llama-3.3-70b-instruct', $all);
        $attr = $all['meta-llama/llama-3.3-70b-instruct'];
        $this->assertEqualsWithDelta(1e-7, $attr['input_cost_per_token'], 1e-12);
        $this->assertEqualsWithDelta(3.2e-7, $attr['output_cost_per_token'], 1e-12);
        $this->assertEqualsWithDelta(4e-8, $attr['cache_read_input_token_cost'], 1e-12);
        $this->assertSame('deepinfra', $attr['litellm_provider']);
        $this->assertInstanceOf(\DateTimeInterface::class, $source->syncedAt());
    }

    public function test_provider_falls_back_to_id_prefix(): void
    {
        config(['ai-finops.pricing.openrouter.enabled' => true]);

        $http = $this->app->make(Http::class);
        $http->fake([
            '*' => $http->response(['data' => [[
                'id' => 'anthropic/claude-opus-4',
                'pricing' => ['prompt' => '0.000015', 'completion' => '0.000075'],
            ]]]),
        ]);

        $all = $this->source($http)->all();
        $this->assertSame('anthropic', $all['anthropic/claude-opus-4']['litellm_provider']);
    }

    public function test_disabled_when_not_enabled(): void
    {
        config(['ai-finops.pricing.openrouter.enabled' => false]);

        $http = $this->app->make(Http::class);
        $http->fake(fn () => throw new \RuntimeException('network must not be hit when disabled'));

        $source = $this->source($http);
        $this->assertSame(0, $source->sync());
        $this->assertSame([], $source->all());
    }

    public function test_disabled_without_key_when_keyless_false(): void
    {
        config(['ai-finops.pricing.openrouter.enabled' => true]);
        config(['ai-finops.pricing.openrouter.allow_keyless' => false]);
        config(['ai-finops.pricing.openrouter.key' => null]);

        $http = $this->app->make(Http::class);
        $http->fake(fn () => throw new \RuntimeException('network must not be hit without a key'));

        $source = $this->source($http);
        $this->assertSame(0, $source->sync());
        $this->assertSame([], $source->all());
    }

    public function test_failed_response_leaves_synced_at_null(): void
    {
        config(['ai-finops.pricing.openrouter.enabled' => true]);

        $http = $this->app->make(Http::class);
        $http->fake(['*' => $http->response('boom', 503)]);

        $source = $this->source($http);
        $this->assertSame(0, $source->sync());
        $this->assertNull($source->syncedAt());
    }
}
