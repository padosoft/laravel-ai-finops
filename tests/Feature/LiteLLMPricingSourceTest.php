<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Padosoft\LaravelAiFinOps\Pricing\LiteLLMPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class LiteLLMPricingSourceTest extends TestCase
{
    public function test_disabled_flag_short_circuits_without_network(): void
    {
        config(['ai-finops.pricing.litellm.enabled' => false]);

        // Fake the HTTP layer and assert it is never called when disabled.
        $http = $this->app->make(Http::class);
        $http->fake(fn () => throw new \RuntimeException('network must not be hit when disabled'));

        $source = new LiteLLMPricingSource($http, $this->app->make(Cache::class), $this->app['config']);

        $this->assertSame([], $source->all());
        $this->assertSame(0, $source->sync());
    }

    public function test_enabled_source_parses_faked_mirror_and_drops_sample_spec(): void
    {
        config(['ai-finops.pricing.litellm.enabled' => true]);

        $http = $this->app->make(Http::class);
        $http->fake([
            '*' => $http->response([
                'sample_spec' => ['note' => 'not a model'],
                'gpt-5.1' => ['input_cost_per_token' => 0.000002, 'litellm_provider' => 'openai'],
            ]),
        ]);

        $source = new LiteLLMPricingSource($http, $this->app->make(Cache::class), $this->app['config']);

        $this->assertSame(1, $source->sync());
        $this->assertArrayHasKey('gpt-5.1', $source->all());
        $this->assertArrayNotHasKey('sample_spec', $source->all());
    }
}
