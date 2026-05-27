<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Padosoft\LaravelAiFinOps\Metering\MeteringListener;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Support\TraceContext;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class TraceContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_trace_context_attributes_calls_per_step_under_one_trace(): void
    {
        $trace = $this->app->make(TraceContext::class);
        $listener = $this->app->make(MeteringListener::class);

        $response = fn () => new AgentResponse('inv', 'x', new Usage(promptTokens: 10, completionTokens: 5), new Meta(provider: 'openai', model: 'gpt-5.1'));

        $trace->within(['trace_id' => 'run-1', 'agent_step' => 'plan', 'cost_center' => 'rnd', 'tenant_id' => 'acme', 'purpose_tag' => 'agent'], function () use ($listener, $response) {
            $listener->recordAgentResponse('ignored-invocation', $response());
        });

        $trace->within(['trace_id' => 'run-1', 'agent_step' => 'answer'], function () use ($listener, $response) {
            $listener->recordAgentResponse('ignored-invocation', $response());
        });

        $rows = UsageRecord::query()->where('trace_id', 'run-1')->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['plan', 'answer'], $rows->pluck('agent_step')->all());
        $this->assertSame('rnd', $rows[0]->cost_center);
        $this->assertSame('acme', $rows[0]->tenant_id);
    }

    public function test_context_is_restored_after_within(): void
    {
        $trace = $this->app->make(TraceContext::class);

        $trace->within(['trace_id' => 'run-x'], fn () => $this->assertSame('run-x', $trace->traceId()));

        $this->assertNull($trace->traceId());
    }

    public function test_trace_endpoint_includes_quality_block(): void
    {
        $listener = $this->app->make(MeteringListener::class);
        $this->app->make(TraceContext::class)->within(['trace_id' => 'run-q'], function () use ($listener) {
            $listener->recordAgentResponse('inv', new AgentResponse('inv', 'x', new Usage(promptTokens: 1), new Meta(provider: 'openai', model: 'gpt-5.1')));
        });

        $this->getJson('/api/ai-finops/usage/run-q/trace')
            ->assertOk()
            ->assertJsonPath('totals.calls', 1)
            ->assertJsonStructure(['quality']);
    }
}
