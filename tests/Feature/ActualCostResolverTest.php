<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Http\Client\Factory as Http;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Pricing\Cost\ActualCostResolverManager;
use Padosoft\LaravelAiFinOps\Pricing\Cost\OpenRouterCostResolver;
use Padosoft\LaravelAiFinOps\Pricing\Cost\RawResponseCapture;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class ActualCostResolverTest extends TestCase
{
    private function manager(RawResponseCapture $cap): ActualCostResolverManager
    {
        return new ActualCostResolverManager([
            'openrouter' => new OpenRouterCostResolver($cap, $this->app['config'], $this->app->make(Http::class)),
        ], $this->app['config']);
    }

    private function envelopeFor(string $provider): AiCallEnvelope
    {
        return new AiCallEnvelope(traceId: 't', provider: $provider, model: 'x');
    }

    public function test_openrouter_returns_summed_cost_in_base_currency(): void
    {
        config(['ai-finops.currency.base' => 'USD']);
        config(['ai-finops.pricing.actual_cost.openrouter.credit_to_currency' => 1.0]);

        $cap = new RawResponseCapture;
        $cap->push(['id' => 'gen-1', 'cost' => 0.0123, 'prompt_tokens' => 100, 'completion_tokens' => 50]);

        $resolved = $this->manager($cap)->resolve($this->envelopeFor('openrouter'));

        $this->assertNotNull($resolved);
        $this->assertEqualsWithDelta(0.0123, $resolved->amount, 1e-9);
        $this->assertSame('USD', $resolved->currency);
        $this->assertSame('openrouter', $resolved->source);
        $this->assertSame(100, $resolved->tokens->input);
    }

    public function test_credit_to_currency_conversion_applies(): void
    {
        config(['ai-finops.pricing.actual_cost.openrouter.credit_to_currency' => 0.9]);

        $cap = new RawResponseCapture;
        $cap->push(['cost' => 1.0]);

        $resolved = $this->manager($cap)->resolve($this->envelopeFor('openrouter'));
        $this->assertEqualsWithDelta(0.9, $resolved->amount, 1e-9);
    }

    public function test_non_mapped_provider_returns_null(): void
    {
        $cap = new RawResponseCapture;
        $cap->push(['cost' => 0.01]); // capture present, but provider isn't openrouter

        $this->assertNull($this->manager($cap)->resolve($this->envelopeFor('openai')));
    }

    public function test_no_capture_returns_null(): void
    {
        $this->assertNull($this->manager(new RawResponseCapture)->resolve($this->envelopeFor('openrouter')));
    }
}
