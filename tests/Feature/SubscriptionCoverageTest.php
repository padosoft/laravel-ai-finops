<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Padosoft\LaravelAiFinOps\Contracts\UsageRecorder;
use Padosoft\LaravelAiFinOps\Metering\MeteringListener;
use Padosoft\LaravelAiFinOps\Models\SubscriptionWindow;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Pricing\Cost\CostResolutionService;
use Padosoft\LaravelAiFinOps\Pricing\Cost\HeuristicTokenEstimator;
use Padosoft\LaravelAiFinOps\Pricing\Cost\NullActualCostResolver;
use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\Pricing\PricingSourceManager;
use Padosoft\LaravelAiFinOps\Support\TenantResolver;
use Padosoft\LaravelAiFinOps\Support\TraceContext;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class SubscriptionCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function listener(): MeteringListener
    {
        $source = new ArrayPricingSource([
            'claude-opus-4' => [
                'input_cost_per_token' => 0.000015,
                'output_cost_per_token' => 0.000075,
                'litellm_provider' => 'anthropic',
            ],
        ], 'litellm', now());

        $registry = new PricingRegistry(new PricingSourceManager(['litellm' => $source], $this->app['config']), $this->app['config']);

        return new MeteringListener(
            $this->app->make(UsageRecorder::class),
            $this->app['config'],
            $registry,
            new CostResolutionService(new NullActualCostResolver, $registry, new CostCalculator, new HeuristicTokenEstimator, $this->app['config']),
            $this->app->make(TenantResolver::class),
            $this->app->make(TraceContext::class),
        );
    }

    private function meter(string $trace): void
    {
        $this->listener()->recordAgentResponse($trace, new AgentResponse(
            $trace,
            'answer',
            new Usage(promptTokens: 1000, completionTokens: 500),
            new Meta(provider: 'anthropic', model: 'claude-opus-4'),
        ));
    }

    public function test_active_window_zeroes_cost_and_tags_covered(): void
    {
        SubscriptionWindow::create([
            'provider' => 'anthropic',
            'label' => 'claude-max',
            'starts_at' => now()->subDay(),
            'ends_at' => null, // open-ended
            'enabled' => true,
        ]);

        $this->meter('cov-1');

        $row = UsageRecord::query()->where('trace_id', 'cov-1')->firstOrFail();

        $this->assertSame('0.00000000', (string) $row->cost_total);
        $this->assertSame('covered', $row->status);
        $this->assertSame('claude-max', $row->metadata['covered_by'] ?? null);
        // Tokens are still recorded for visibility / quota tracking.
        $this->assertSame(1000, (int) $row->tokens_input);
        $this->assertSame(500, (int) $row->tokens_output);
        // The would-be rate is preserved for "value consumed" analysis.
        $this->assertSame(0.000015, $row->metadata['rate_input'] ?? null);
    }

    public function test_expired_window_prices_normally(): void
    {
        SubscriptionWindow::create([
            'provider' => 'anthropic',
            'label' => 'claude-max',
            'starts_at' => now()->subDays(40),
            'ends_at' => now()->subDay(), // ended yesterday
            'enabled' => true,
        ]);

        $this->meter('cov-2');

        $row = UsageRecord::query()->where('trace_id', 'cov-2')->firstOrFail();

        // 1000*15e-6 + 500*75e-6 = 0.015 + 0.0375 = 0.0525
        $this->assertSame('0.05250000', (string) $row->cost_total);
        $this->assertSame('recorded', $row->status);
        $this->assertArrayNotHasKey('covered_by', (array) $row->metadata);
    }

    public function test_disabled_window_does_not_cover(): void
    {
        SubscriptionWindow::create([
            'provider' => 'anthropic',
            'label' => 'claude-max',
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'enabled' => false,
        ]);

        $this->meter('cov-3');

        $row = UsageRecord::query()->where('trace_id', 'cov-3')->firstOrFail();
        $this->assertSame('recorded', $row->status);
    }

    public function test_window_for_other_provider_does_not_cover(): void
    {
        SubscriptionWindow::create([
            'provider' => 'openai',
            'label' => 'openai-pro',
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'enabled' => true,
        ]);

        $this->meter('cov-4');

        $row = UsageRecord::query()->where('trace_id', 'cov-4')->firstOrFail();
        $this->assertSame('recorded', $row->status);
    }
}
