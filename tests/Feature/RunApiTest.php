<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Data\RunEvent as RunEventData;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\RunEventKind;
use Padosoft\LaravelAiFinOps\Enums\RunEventStatus;
use Padosoft\LaravelAiFinOps\Models\RunEvent;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class RunApiTest extends TestCase
{
    use RefreshDatabase;

    private function step(string $invocationId, int $number, float $cost = 0.01, ?string $parent = null, ?string $parentTool = null): void
    {
        RunEvent::fromData(new RunEventData(
            invocationId: $invocationId,
            kind: RunEventKind::Step,
            status: RunEventStatus::Completed,
            parentInvocationId: $parent,
            parentToolInvocationId: $parentTool,
            stepNumber: $number,
            agent: 'App\\Agents\\Support',
            provider: 'openai',
            model: 'gpt-4o-mini',
            tokens: new TokenUsage(input: 100, output: 20),
            costTotal: $cost,
            currency: 'USD',
            durationMs: 200,
        ))->save();
    }

    private function tool(string $invocationId, string $toolInvocationId, string $name, RunEventStatus $status = RunEventStatus::Completed): void
    {
        RunEvent::fromData(new RunEventData(
            invocationId: $invocationId,
            kind: RunEventKind::Tool,
            status: $status,
            toolInvocationId: $toolInvocationId,
            toolName: $name,
            agent: 'App\\Agents\\Support',
            durationMs: 45,
        ))->save();
    }

    public function test_the_index_returns_one_row_per_run(): void
    {
        $this->step('inv_a', 1);
        $this->step('inv_a', 2);
        $this->tool('inv_a', 'tool_1', 'lookup_order');
        $this->step('inv_b', 1);

        $response = $this->getJson('/api/ai-finops/runs')->assertOk();

        $this->assertCount(2, $response->json('data'));

        $first = collect($response->json('data'))->firstWhere('invocation_id', 'inv_a');
        $this->assertSame(2, (int) $first['steps']);
        $this->assertSame(1, (int) $first['tools']);
        $this->assertSame(0, (int) $first['failures']);
    }

    public function test_failed_only_narrows_to_runs_with_a_failure(): void
    {
        $this->step('inv_ok', 1);
        $this->tool('inv_bad', 'tool_2', 'refund_order', RunEventStatus::Failed);

        $response = $this->getJson('/api/ai-finops/runs?failed_only=1')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('inv_bad', $response->json('data.0.invocation_id'));
    }

    public function test_showing_a_run_returns_its_steps_tools_and_the_runs_it_delegated_to(): void
    {
        $this->step('inv_parent', 1, cost: 0.02);
        $this->tool('inv_parent', 'tool_call_7', 'ask_specialist');

        // The sub-agent's own run, recorded with the tool call it was delegated from.
        $this->step('inv_child', 1, cost: 0.05, parent: 'inv_parent', parentTool: 'tool_call_7');

        $response = $this->getJson('/api/ai-finops/runs/inv_parent')->assertOk();

        $this->assertCount(1, $response->json('steps'));
        $this->assertCount(1, $response->json('tools'));
        $this->assertNull($response->json('parent'));

        $children = $response->json('children');
        $this->assertCount(1, $children);
        $this->assertSame('inv_child', $children[0]['invocation_id']);
        // The edge that makes the chain a tree: which tool call spawned that run.
        $this->assertSame('tool_call_7', $children[0]['called_from_tool_invocation_id']);
    }

    public function test_a_delegated_run_names_the_run_that_called_it(): void
    {
        $this->step('inv_child', 1, parent: 'inv_parent', parentTool: 'tool_call_7');

        $this->getJson('/api/ai-finops/runs/inv_child')
            ->assertOk()
            ->assertJsonPath('parent.invocation_id', 'inv_parent')
            ->assertJsonPath('parent.called_from_tool_invocation_id', 'tool_call_7');
    }
}
