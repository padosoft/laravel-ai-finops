<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Padosoft\LaravelAiFinOps\Contracts\UsageRecorder;
use Padosoft\LaravelAiFinOps\Metering\MeteringListener;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\Support\TenantResolver;
use Padosoft\LaravelAiFinOps\Support\TraceContext;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class MeteringCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_metered_call_is_priced_from_the_registry(): void
    {
        $source = new ArrayPricingSource([
            'gpt-5.1' => [
                'input_cost_per_token' => 0.000002,
                'output_cost_per_token' => 0.000008,
                'litellm_provider' => 'openai',
            ],
        ]);

        $listener = new MeteringListener(
            $this->app->make(UsageRecorder::class),
            $this->app['config'],
            new PricingRegistry($source, $this->app['config']),
            new CostCalculator,
            $this->app->make(TenantResolver::class),
            $this->app->make(TraceContext::class),
        );

        $listener->recordAgentResponse('inv-cost', new AgentResponse(
            'inv-cost',
            'answer',
            new Usage(promptTokens: 1000, completionTokens: 500),
            new Meta(provider: 'openai', model: 'gpt-5.1'),
        ));

        $row = UsageRecord::query()->where('trace_id', 'inv-cost')->firstOrFail();

        // 1000*2e-6 + 500*8e-6 = 0.002 + 0.004 = 0.006
        $this->assertSame('0.00600000', (string) $row->cost_total);
        $this->assertSame('USD', $row->currency);
        $this->assertSame('litellm', $row->metadata['price_source'] ?? null);
    }
}
