<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_').'approvals';
    }

    private function connection(): ?string
    {
        return config('ai-finops.storage.connection');
    }

    public function up(): void
    {
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->string('tenant_id', 64)->nullable();
            $table->string('cost_center', 128)->nullable();
            $table->decimal('estimated_cost', 18, 8)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('status', 16)->default('pending')->index(); // pending|approved|rejected
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->string('reason')->nullable();
            $table->string('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }
};
