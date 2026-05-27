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
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('model', 128);
            $table->string('provider', 64)->nullable();
            $table->decimal('input_cost_per_token', 18, 12)->default(0);
            $table->decimal('output_cost_per_token', 18, 12)->default(0);
            $table->decimal('cache_read_cost_per_token', 18, 12)->nullable();
            $table->decimal('cache_write_cost_per_token', 18, 12)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->timestamps();

            $table->unique(['model', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
