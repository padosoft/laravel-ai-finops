<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'run_events';
    }

    private function connection(): ?string
    {
        return config('ai-finops.storage.connection');
    }

    public function up(): void
    {
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Correlation. `invocation_id` is laravel/ai's own id for the whole run,
            // threaded through every step and tool event since 0.11. The parent pair
            // is set when this run was delegated from a tool of another run — an
            // agent used as a tool — and is what makes the who-called-whom tree.
            $table->string('invocation_id', 64)->index();
            $table->string('parent_invocation_id', 64)->nullable()->index();
            $table->string('parent_tool_invocation_id', 64)->nullable();

            $table->string('kind', 8);      // step | tool
            $table->string('status', 12);   // completed | failed

            $table->unsignedInteger('step_number')->nullable();
            $table->boolean('is_final_step')->nullable();

            $table->string('tool_invocation_id', 64)->nullable()->index();
            $table->string('tool_name', 191)->nullable();

            $table->string('agent', 191)->nullable();
            $table->string('provider', 64)->nullable()->index();
            $table->string('model', 128)->nullable()->index();
            $table->string('finish_reason', 32)->nullable();

            // Per-step usage. Tool rows carry none: a tool call spends no tokens of
            // its own — the tokens it costs are the ones the next step pays to read
            // its result, which is that step's row.
            $table->unsignedBigInteger('tokens_input')->default(0);
            $table->unsignedBigInteger('tokens_output')->default(0);
            $table->unsignedBigInteger('tokens_cached')->default(0);
            $table->unsignedBigInteger('tokens_reasoning')->default(0);
            $table->decimal('cost_total', 18, 8)->default(0);
            $table->string('currency', 3)->nullable();

            // Wall time reported by laravel/ai, in milliseconds. Present on completed
            // AND failed rows: how long a tool ran before throwing is the number that
            // tells a timeout apart from a rejection.
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('error_class', 191)->nullable();
            $table->text('error_message')->nullable();

            // Attribution, mirrored from the cost ledger so this table answers
            // "which tenant / cost centre / delegation" without a join.
            $table->string('tenant_id', 64)->nullable()->index();
            $table->string('cost_center', 128)->nullable();
            $table->string('delegation_grant_id', 64)->nullable()->index();

            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['invocation_id', 'step_number'], 'ai_finops_run_events_run_step_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
