<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'subscription_windows';
    }

    private function connection(): ?string
    {
        return config('ai-finops.storage.connection');
    }

    public function up(): void
    {
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table): void {
            $table->bigIncrements('id');

            // A flat-rate subscription (e.g. "claude-max") that covers calls to a
            // provider for the window [starts_at, ends_at]. Within it, calls cost 0.
            $table->string('provider', 64)->index();
            $table->string('label', 128);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable(); // null = open-ended (until operator shortens it)
            $table->boolean('enabled')->default(true);

            // Optional narrowing. Null = applies to all tenants / all models.
            $table->string('tenant_id', 64)->nullable()->index();
            $table->string('model', 128)->nullable();

            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['provider', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
