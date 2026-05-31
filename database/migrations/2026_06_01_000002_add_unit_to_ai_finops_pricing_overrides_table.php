<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'pricing_overrides';
    }

    private function connection(): ?string
    {
        return config('ai-finops.storage.connection');
    }

    public function up(): void
    {
        Schema::connection($this->connection())->table($this->table(), function (Blueprint $table): void {
            // How the entered cost is expressed. `per_million` is divided by 1e6
            // on read (operator convenience for feed-less providers like regolo).
            $table->string('unit', 16)->default('per_token')->after('output_cost_per_token');
            $table->date('effective_from')->nullable()->after('currency');
            $table->string('note', 255)->nullable()->after('effective_from');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->table($this->table(), function (Blueprint $table): void {
            $table->dropColumn(['unit', 'effective_from', 'note']);
        });
    }
};
