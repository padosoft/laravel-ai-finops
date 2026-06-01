<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Padosoft\LaravelAiFinOps\Contracts\TokenEstimator;
use Padosoft\LaravelAiFinOps\Pricing\Cost\HeuristicTokenEstimator;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class TokenEstimatorTest extends TestCase
{
    public function test_heuristic_estimates_by_chars_and_words(): void
    {
        $est = new HeuristicTokenEstimator;

        // "one two three four" = 18 chars, 4 words → max(ceil(18/4)=5, ceil(4*1.3)=6) = 6
        $this->assertSame(6, $est->estimate('one two three four')->input);
    }

    public function test_heuristic_flattens_chat_messages(): void
    {
        $est = new HeuristicTokenEstimator;

        $u = $est->estimate([
            ['role' => 'system', 'content' => 'You are helpful'],
            ['role' => 'user', 'content' => 'Hello there friend'],
        ]);

        $this->assertGreaterThan(0, $u->input);
    }

    public function test_container_binds_heuristic_when_tiktoken_absent(): void
    {
        // yethee/tiktoken is not a dependency in CI → heuristic is the bound impl.
        $this->assertInstanceOf(HeuristicTokenEstimator::class, $this->app->make(TokenEstimator::class));
    }
}
