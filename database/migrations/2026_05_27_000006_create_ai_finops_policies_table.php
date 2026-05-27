<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'policies';
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

            // Match conditions (all that are set must hold).
            $table->string('scope_type', 32)->default('global');
            $table->string('scope_id', 128)->nullable();
            $table->decimal('min_cost', 18, 8)->nullable();   // applies when estimated cost >= min_cost
            $table->string('model_match', 128)->nullable();   // exact model match

            // Action when matched: allow|block|throttle|downgrade|queue|require_approval
            $table->string('action', 32)->default('block');
            $table->string('action_param', 128)->nullable();  // e.g. downgrade target model

            $table->unsignedInteger('priority')->default(100); // lower = evaluated first
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
