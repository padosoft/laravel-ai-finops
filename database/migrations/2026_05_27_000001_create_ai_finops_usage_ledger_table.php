<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'usage_ledger';
    }

    private function connection(): ?string
    {
        return config('ai-finops.storage.connection');
    }

    public function up(): void
    {
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Tracing / agentic attribution
            $table->string('trace_id', 64)->index();
            $table->string('span_id', 64)->nullable();
            $table->string('parent_span_id', 64)->nullable();
            $table->string('agent_step')->nullable();
            $table->string('purpose_tag')->nullable();

            // Provider / model
            $table->string('provider', 64)->index();
            $table->string('model', 128)->index();
            $table->string('modality', 16)->default('text');
            $table->string('status', 16)->default('recorded')->index();

            // Ownership / allocation
            $table->string('tenant_id', 64)->nullable()->index();
            $table->string('user_id', 64)->nullable();
            $table->string('cost_center', 128)->nullable()->index();

            // Tokens
            $table->unsignedBigInteger('tokens_input')->default(0);
            $table->unsignedBigInteger('tokens_output')->default(0);
            $table->unsignedBigInteger('tokens_cached')->default(0);
            $table->unsignedBigInteger('tokens_reasoning')->default(0);

            // Cost (high precision; stored in the row currency)
            $table->decimal('cost_input', 18, 8)->default(0);
            $table->decimal('cost_output', 18, 8)->default(0);
            $table->decimal('cost_cached', 18, 8)->default(0);
            $table->decimal('cost_total', 18, 8)->default(0);
            $table->string('currency', 3)->default('USD');

            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
