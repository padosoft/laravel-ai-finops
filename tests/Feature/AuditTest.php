<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Models\AuditEntry;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_governance_mutations_are_audited(): void
    {
        $budget = Budget::create(['name' => 'Org', 'scope_type' => 'global', 'limit_amount' => 100, 'period' => 'monthly']);
        $budget->update(['limit_amount' => 200]);
        $budget->delete();

        $events = AuditEntry::query()->where('subject_type', 'Budget')->pluck('event')->all();

        $this->assertContains('created', $events);
        $this->assertContains('updated', $events);
        $this->assertContains('deleted', $events);
    }

    public function test_audit_endpoint_lists_and_filters(): void
    {
        Budget::create(['name' => 'Org', 'scope_type' => 'global', 'limit_amount' => 100, 'period' => 'monthly']);

        $this->getJson('/api/ai-finops/audit?subject_type=Budget&event=created')
            ->assertOk()
            ->assertJsonPath('data.0.subject_type', 'Budget')
            ->assertJsonPath('data.0.event', 'created');
    }

    public function test_audit_can_be_disabled(): void
    {
        config(['ai-finops.audit.enabled' => false]);

        Budget::create(['name' => 'Org', 'scope_type' => 'global', 'limit_amount' => 100, 'period' => 'monthly']);

        $this->assertSame(0, AuditEntry::query()->count());
    }
}
