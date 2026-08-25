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
        Schema::connection($this->connection())->table($this->table(), function (Blueprint $table): void {
            // `trace_id` is the ambient trace when one is set (laravel-flow sets one
            // per step), which means the provider's own invocation id was being
            // overwritten and the ledger could no longer be joined to the run events
            // laravel/ai emits. This column always holds laravel/ai's id, whatever
            // the ambient trace decided to call the row.
            $table->string('invocation_id', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('invocation_id');
        });
    }
};
