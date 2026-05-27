<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class ConsoleCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function ledgerRow(float $cost, ?string $createdAt = null): void
    {
        $row = UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: uniqid('t', true), provider: 'openai', model: 'gpt-5.1',
            tokens: new TokenUsage(input: 10, output: 5), cost: new CostBreakdown(total: $cost, currency: 'USD'),
        ));
        $row->save();

        if ($createdAt !== null) {
            $row->forceFill(['created_at' => $createdAt])->save();
        }
    }

    public function test_report_command_summarizes_spend(): void
    {
        $this->ledgerRow(0.05);
        $this->ledgerRow(0.03);

        $this->artisan('ai-finops:report', ['--days' => 7])
            ->assertSuccessful()
            ->expectsOutputToContain('Total cost: 0.08');
    }

    public function test_prune_deletes_old_rows(): void
    {
        $this->ledgerRow(0.05); // recent
        $this->ledgerRow(0.05, now()->subDays(800)->toDateTimeString()); // stale

        $this->artisan('ai-finops:prune', ['--days' => 365])
            ->assertSuccessful();

        $this->assertSame(1, UsageRecord::query()->count());
    }

    public function test_prune_rejects_invalid_days(): void
    {
        $this->artisan('ai-finops:prune', ['--days' => 0])
            ->assertFailed();
    }
}
