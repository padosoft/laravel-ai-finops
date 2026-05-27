<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Models\SpendApproval;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class ApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    private function pending(): SpendApproval
    {
        return SpendApproval::create([
            'provider' => 'openai', 'model' => 'gpt-5.1', 'estimated_cost' => 5.0,
            'currency' => 'USD', 'status' => 'pending', 'reason' => 'policy: legal',
        ]);
    }

    public function test_list_filters_by_status(): void
    {
        $this->pending();

        $this->getJson('/api/ai-finops/approvals?status=pending')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_approve_sets_status_and_decider(): void
    {
        $a = $this->pending();

        $this->postJson("/api/ai-finops/approvals/{$a->id}/approve", ['decided_by' => 'cfo@acme'])
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('decided_by', 'cfo@acme');
    }

    public function test_reject_then_second_decision_conflicts(): void
    {
        $a = $this->pending();

        $this->postJson("/api/ai-finops/approvals/{$a->id}/reject")->assertOk()->assertJsonPath('status', 'rejected');
        $this->postJson("/api/ai-finops/approvals/{$a->id}/approve")->assertStatus(409);
    }
}
