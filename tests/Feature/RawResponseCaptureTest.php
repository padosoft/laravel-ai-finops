<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Padosoft\LaravelAiFinOps\Pricing\Cost\HttpUsageCaptureMiddleware;
use Padosoft\LaravelAiFinOps\Pricing\Cost\RawResponseCapture;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class RawResponseCaptureTest extends TestCase
{
    public function test_sum_cost_aggregates_multi_step_captures_then_clears(): void
    {
        $cap = new RawResponseCapture;
        $cap->push(['cost' => 0.01, 'currency' => 'credits', 'prompt_tokens' => 100, 'completion_tokens' => 50]);
        $cap->push(['cost' => 0.005, 'currency' => 'credits', 'prompt_tokens' => 20, 'completion_tokens' => 10]);

        $sum = $cap->sumCost();
        $this->assertEqualsWithDelta(0.015, $sum['cost'], 1e-9);
        $this->assertSame(120, $sum['tokens']->input);
        $this->assertSame(60, $sum['tokens']->output);

        // Draining cleared the buffer.
        $this->assertTrue($cap->isEmpty());
        $this->assertNull($cap->sumCost());
    }

    public function test_middleware_captures_openrouter_cost_and_keeps_body_readable(): void
    {
        $cap = new RawResponseCapture;
        $mw = HttpUsageCaptureMiddleware::make($cap);

        $json = json_encode([
            'id' => 'gen-123',
            'usage' => [
                'cost' => 0.0123,
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'completion_tokens_details' => ['reasoning_tokens' => 7],
            ],
        ]);
        $response = new Response(200, ['Content-Type' => 'application/json'], $json);

        $returned = $mw($response);

        // Body still fully readable downstream.
        $this->assertStringContainsString('gen-123', (string) $returned->getBody());

        $sum = $cap->sumCost();
        $this->assertEqualsWithDelta(0.0123, $sum['cost'], 1e-9);
        $this->assertSame(7, $sum['tokens']->reasoning);
    }

    public function test_middleware_ignores_responses_without_cost(): void
    {
        $cap = new RawResponseCapture;
        $mw = HttpUsageCaptureMiddleware::make($cap);

        // OpenAI-shaped usage (tokens only, no cost) → no capture.
        $response = new Response(200, [], json_encode(['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50]]));
        $mw($response);

        $this->assertTrue($cap->isEmpty());
    }
}
