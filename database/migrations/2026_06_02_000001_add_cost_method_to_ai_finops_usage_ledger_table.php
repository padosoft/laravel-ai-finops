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
            // How the cost was derived: actual (provider-billed) | computed (tokens×tariff)
            // | estimated (tokens guessed×tariff) | covered (flat-rate subscription → 0).
            $table->string('cost_method', 16)->default('computed')->index();
            $table->boolean('tokens_estimated')->default(false);
            // The provider's real invoiced amount when known, distinct from cost_total.
            $table->decimal('billed_cost', 18, 8)->nullable();
            $table->string('billed_currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->table($this->table(), function (Blueprint $table): void {
            $table->dropColumn(['cost_method', 'tokens_estimated', 'billed_cost', 'billed_currency']);
        });
    }
};
