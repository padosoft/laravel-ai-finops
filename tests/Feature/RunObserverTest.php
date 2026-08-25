<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Padosoft\LaravelAiFinOps\Enums\CallStatus;
use Padosoft\LaravelAiFinOps\Enums\RunEventKind;
use Padosoft\LaravelAiFinOps\Enums\RunEventStatus;
use Padosoft\LaravelAiFinOps\Metering\RunObserver;
use Padosoft\LaravelAiFinOps\Models\RunEvent;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Tests\Support\FakeTool;
use Padosoft\LaravelAiFinOps\Tests\TestCase;
use RuntimeException;

class RunObserverTest extends TestCase
{
    use RefreshDatabase;

    private function observer(): RunObserver
    {
        return $this->app->make(RunObserver::class);
    }

    private function agent(): Agent
    {
        return $this->createStub(Agent::class);
    }

    private function provider(): TextProvider
    {
        return $this->createStub(TextProvider::class);
    }

    private function stepResponse(int $prompt = 100, int $completion = 20): StepResponse
    {
        return new StepResponse(
            text: 'hello',
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new Usage(promptTokens: $prompt, completionTokens: $completion),
            meta: new Meta(provider: 'openai', model: 'gpt-4o-mini'),
        );
    }

    private function stepCompleted(string $invocationId, int $stepNumber, int $prompt = 100, int $completion = 20, float $time = 250.0): StepCompleted
    {
        return new StepCompleted(
            $invocationId, $stepNumber, $this->agent(), $this->provider(), 'gpt-4o-mini', false,
            $this->stepResponse($prompt, $completion), $time,
        );
    }

    public function test_a_completed_step_is_recorded_with_its_usage_and_wall_time(): void
    {
        $this->observer()->handleStepCompleted($this->stepCompleted('inv_1', 1));

        $row = RunEvent::query()->sole();

        $this->assertSame('inv_1', $row->invocation_id);
        $this->assertSame(RunEventKind::Step->value, $row->kind);
        $this->assertSame(RunEventStatus::Completed->value, $row->status);
        $this->assertSame(1, $row->step_number);
        $this->assertSame(100, $row->tokens_input);
        $this->assertSame(20, $row->tokens_output);
        $this->assertSame(250, $row->duration_ms);
        $this->assertSame('openai', $row->provider);
        $this->assertSame(FinishReason::Stop->value, $row->finish_reason);
    }

    public function test_a_failed_step_records_the_exception_and_how_long_it_ran(): void
    {
        $this->observer()->handleStepFailed(new StepFailed(
            'inv_2', 3, $this->agent(), $this->provider(), 'gpt-4o-mini', false,
            new RuntimeException('upstream exploded'), 1_800.0,
        ));

        $row = RunEvent::query()->sole();

        $this->assertSame(RunEventStatus::Failed->value, $row->status);
        $this->assertSame(RuntimeException::class, $row->error_class);
        $this->assertSame('upstream exploded', $row->error_message);
        $this->assertSame(1_800, $row->duration_ms);
    }

    public function test_a_tool_invocation_is_named_the_way_laravel_ai_names_it(): void
    {
        $this->observer()->handleToolInvoked(new ToolInvoked(
            'inv_3', 'tool_inv_1', $this->agent(), new FakeTool('refund_order'), ['id' => 7], 'done', 42.0,
        ));

        $row = RunEvent::query()->sole();

        $this->assertSame(RunEventKind::Tool->value, $row->kind);
        $this->assertSame('refund_order', $row->tool_name);
        $this->assertSame('tool_inv_1', $row->tool_invocation_id);
        $this->assertSame(42, $row->duration_ms);
        $this->assertSame(0, $row->tokens_input);
    }

    public function test_a_tool_that_throws_is_recorded_with_the_time_it_burned_first(): void
    {
        $this->observer()->handleToolFailed(new ToolFailed(
            'inv_4', 'tool_inv_2', $this->agent(), new FakeTool, ['id' => 7],
            new RuntimeException('timed out'), 9_000.0,
        ));

        $row = RunEvent::query()->sole();

        $this->assertSame(RunEventStatus::Failed->value, $row->status);
        $this->assertSame('timed out', $row->error_message);
        // The number that tells a timeout apart from an instant rejection.
        $this->assertSame(9_000, $row->duration_ms);
    }

    public function test_a_terminal_failure_bills_the_steps_that_did_complete(): void
    {
        $observer = $this->observer();

        $observer->handleStepCompleted($this->stepCompleted('inv_5', 1, prompt: 100, completion: 20));
        $observer->handleStepCompleted($this->stepCompleted('inv_5', 2, prompt: 300, completion: 50));

        $observer->handleAgentFailed(new AgentFailed('inv_5', $this->prompt(), new RuntimeException('gave up')));

        $ledger = UsageRecord::query()->sole();

        $this->assertSame(CallStatus::Failed->value, $ledger->status);
        // 100 + 300 in, 20 + 50 out: the run died, the tokens were still charged.
        $this->assertSame(400, $ledger->tokens_input);
        $this->assertSame(70, $ledger->tokens_output);
        $this->assertSame('inv_5', $ledger->invocation_id);
        $this->assertSame(2, $ledger->metadata['completed_steps']);
        $this->assertSame(RuntimeException::class, $ledger->metadata['error_class']);
    }

    public function test_a_failure_before_any_step_completed_writes_no_ledger_row(): void
    {
        $this->observer()->handleAgentFailed(new AgentFailed('inv_6', $this->prompt(), new RuntimeException('dead on arrival')));

        $this->assertSame(0, UsageRecord::query()->count());
    }

    public function test_a_run_that_succeeded_is_not_billed_twice_when_it_later_fails(): void
    {
        $observer = $this->observer();

        $observer->handleStepCompleted($this->stepCompleted('inv_7', 1));
        // The metering listener bills a successful run as a whole; the observer only
        // releases its accumulator. A stray AgentFailed afterwards must find nothing.
        $observer->handleAgentPrompted(new AgentPrompted(
            'inv_7', $this->prompt(), $this->createStub(AgentResponse::class),
        ));

        $observer->handleAgentFailed(new AgentFailed('inv_7', $this->prompt(), new RuntimeException('late')));

        $this->assertSame(0, UsageRecord::query()->count());
    }

    public function test_a_delegated_run_records_the_invocation_and_tool_it_was_called_from(): void
    {
        ParentInvocation::within('inv_parent', 'tool_inv_9', function (): void {
            $this->observer()->handleStepCompleted($this->stepCompleted('inv_child', 1));
        });

        $row = RunEvent::query()->sole();

        $this->assertSame('inv_child', $row->invocation_id);
        $this->assertSame('inv_parent', $row->parent_invocation_id);
        $this->assertSame('tool_inv_9', $row->parent_tool_invocation_id);
    }

    public function test_error_messages_can_be_switched_off(): void
    {
        config()->set('ai-finops.run_events.capture_error_messages', false);

        $this->observer()->handleToolFailed(new ToolFailed(
            'inv_8', 'tool_inv_3', $this->agent(), new FakeTool, [],
            new RuntimeException('contains the prompt back at you'), 10.0,
        ));

        $row = RunEvent::query()->sole();

        $this->assertNull($row->error_message);
        $this->assertSame(RuntimeException::class, $row->error_class);
    }

    private function prompt(): AgentPrompt
    {
        return new AgentPrompt($this->agent(), 'hi', [], $this->provider(), 'gpt-4o-mini');
    }
}
