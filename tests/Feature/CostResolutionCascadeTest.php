<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as Http;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Padosoft\LaravelAiFinOps\Contracts\UsageRecorder;
use Padosoft\LaravelAiFinOps\Metering\MeteringListener;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Pricing\Cost\ActualCostResolverManager;
use Padosoft\LaravelAiFinOps\Pricing\Cost\CostResolutionService;
use Padosoft\LaravelAiFinOps\Pricing\Cost\HeuristicTokenEstimator;
use Padosoft\LaravelAiFinOps\Pricing\Cost\OpenRouterCostResolver;
use Padosoft\LaravelAiFinOps\Pricing\Cost\RawResponseCapture;
use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\Pricing\PricingSourceManager;
use Padosoft\LaravelAiFinOps\Support\TenantResolver;
use Padosoft\LaravelAiFinOps\Support\TraceContext;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

/**
 * Verifies the a→b→c cascade end-to-end through the metering hook, simulating each
 * provider's real response shape: OpenRouter returns cost (a); OpenAI/Anthropic/
 * Gemini/regolo return tokens only (b); a provider with no usage falls to estimate (c).
 */
class CostResolutionCascadeTest extends TestCase
{
    use RefreshDatabase;

    private RawResponseCapture $capture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capture = new RawResponseCapture;

        // Tariff source covering the token-bearing providers (b). Prices are per-token.
        $source = new ArrayPricingSource([
            'gpt-5.1' => ['input_cost_per_token' => 2e-6, 'output_cost_per_token' => 8e-6, 'litellm_provider' => 'openai'],
            'claude-opus-4' => ['input_cost_per_token' => 15e-6, 'output_cost_per_token' => 75e-6, 'litellm_provider' => 'anthropic'],
            'gemini-3-pro' => ['input_cost_per_token' => 1e-6, 'output_cost_per_token' => 4e-6, 'litellm_provider' => 'gemini'],
            'mystery-model' => ['input_cost_per_token' => 1e-6, 'output_cost_per_token' => 2e-6, 'litellm_provider' => 'whoknows'],
        ], 'litellm', now());

        $registry = new PricingRegistry(new PricingSourceManager(['litellm' => $source], $this->app['config']), $this->app['config']);

        $actual = new ActualCostResolverManager([
            'openrouter' => new OpenRouterCostResolver($this->capture, $this->app['config'], $this->app->make(Http::class)),
        ], $this->app['config']);

        $costs = new CostResolutionService($actual, $registry, new CostCalculator, new HeuristicTokenEstimator, $this->app['config']);

        $this->app->instance(MeteringListener::class, new MeteringListener(
            $this->app->make(UsageRecorder::class),
            $this->app['config'],
            $registry,
            $costs,
            $this->app->make(TenantResolver::class),
            $this->app->make(TraceContext::class),
        ));
    }

    private function meter(string $trace, string $provider, string $model, Usage $usage, string $text = 'answer', mixed $prompt = null): UsageRecord
    {
        $this->app->make(MeteringListener::class)->recordAgentResponse(
            $trace,
            new AgentResponse($trace, $text, $usage, new Meta(provider: $provider, model: $model)),
            $prompt,
        );

        return UsageRecord::query()->where('trace_id', $trace)->firstOrFail();
    }

    // (a) OpenRouter returns the real billed cost → method=actual, billed_cost set.
    public function test_a_openrouter_uses_actual_billed_cost(): void
    {
        $this->capture->push(['id' => 'gen-1', 'cost' => 0.0042, 'prompt_tokens' => 100, 'completion_tokens' => 50]);

        $row = $this->meter('casea', 'openrouter', 'meta-llama/llama-3.3-70b-instruct', new Usage(promptTokens: 100, completionTokens: 50));

        $this->assertSame('actual', $row->cost_method);
        $this->assertSame('0.00420000', (string) $row->cost_total);
        $this->assertSame('0.00420000', (string) $row->billed_cost);
        $this->assertFalse($row->tokens_estimated);
    }

    // (b) Token-bearing providers → method=computed = tokens × tariff.
    public function test_b_openai_computes_from_tokens(): void
    {
        $row = $this->meter('caseb-oai', 'openai', 'gpt-5.1', new Usage(promptTokens: 1000, completionTokens: 500));
        // 1000*2e-6 + 500*8e-6 = 0.006
        $this->assertSame('computed', $row->cost_method);
        $this->assertSame('0.00600000', (string) $row->cost_total);
        $this->assertNull($row->billed_cost);
        $this->assertFalse($row->tokens_estimated);
    }

    public function test_b_anthropic_computes_from_tokens(): void
    {
        $row = $this->meter('caseb-ant', 'anthropic', 'claude-opus-4', new Usage(promptTokens: 100, completionTokens: 100));
        // 100*15e-6 + 100*75e-6 = 0.009
        $this->assertSame('computed', $row->cost_method);
        $this->assertSame('0.00900000', (string) $row->cost_total);
    }

    public function test_b_gemini_computes_from_tokens(): void
    {
        $row = $this->meter('caseb-gem', 'gemini', 'gemini-3-pro', new Usage(promptTokens: 2000, completionTokens: 1000));
        // 2000*1e-6 + 1000*4e-6 = 0.006
        $this->assertSame('computed', $row->cost_method);
        $this->assertSame('0.00600000', (string) $row->cost_total);
    }

    public function test_b_regolo_computes_from_manual_eur_per_million_override(): void
    {
        // regolo has no price feed → manual per-1M EUR override (M8); provider_source_map routes regolo→manual.
        PricingOverride::create([
            'model' => 'llama-3.3-70b', 'provider' => 'regolo',
            'input_cost_per_token' => 0.60, 'output_cost_per_token' => 2.70,
            'unit' => 'per_million', 'currency' => 'EUR',
        ]);

        $row = $this->meter('caseb-reg', 'regolo', 'llama-3.3-70b', new Usage(promptTokens: 1_000_000, completionTokens: 1_000_000));

        $this->assertSame('computed', $row->cost_method);
        // 1M*(0.60/1M) + 1M*(2.70/1M) = 0.60 + 2.70 = 3.30 EUR
        $this->assertSame('3.30000000', (string) $row->cost_total);
        $this->assertSame('EUR', $row->currency);
    }

    // (c) No usage at all → estimate tokens from prompt+completion → method=estimated.
    public function test_c_estimates_tokens_when_usage_absent(): void
    {
        $row = $this->meter(
            'casec',
            'whoknows',
            'mystery-model',
            new Usage(promptTokens: 0, completionTokens: 0),
            text: 'this is the model answer text',
            prompt: 'this is the user prompt text',
        );

        $this->assertSame('estimated', $row->cost_method);
        $this->assertTrue($row->tokens_estimated);
        $this->assertGreaterThan(0, (int) $row->tokens_input);   // estimated from prompt
        $this->assertGreaterThan(0, (int) $row->tokens_output);  // estimated from completion
        $this->assertGreaterThan(0, (float) $row->cost_total);
    }
}
