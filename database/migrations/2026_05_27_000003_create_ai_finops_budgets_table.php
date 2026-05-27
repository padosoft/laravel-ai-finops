<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'budgets';
    }

    private function connection(): ?string
    {
        return config('ai-finops.storage.connection');
    }

    public function up(): void
    {
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable()->index();

            $table->string('scope_type', 32)->default('global');
            $table->string('scope_id', 128)->nullable();

            $table->decimal('limit_amount', 18, 6);
            $table->string('currency', 3)->default('USD');

            $table->string('period', 16)->default('monthly');
            $table->unsignedInteger('rolling_days')->default(30);

            // Soft limit (% of limit) triggers alerts/warnings; hard budgets block.
            $table->unsignedTinyInteger('soft_limit_pct')->nullable();
            $table->boolean('hard')->default(true);
            $table->boolean('enabled')->default(true)->index();

            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
