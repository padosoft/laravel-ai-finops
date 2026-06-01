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
            // Unit rate for non-token (media) pricing — used with unit values
            // per_second | per_image | per_megapixel | per_request (e.g. fal.ai).
            $table->decimal('unit_rate', 18, 12)->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('unit_rate');
        });
    }
};
