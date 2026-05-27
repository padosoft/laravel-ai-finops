<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function prefix(): string
    {
        return config('ai-finops.storage.table_prefix', 'ai_finops_');
    }

    private function connection(): ?string
    {
        return config('ai-finops.storage.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->connection());

        $schema->create($this->prefix().'credit_pools', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('scope_type', 32)->default('tenant');
            $table->string('scope_id', 128)->nullable();
            $table->decimal('balance', 18, 6)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        $schema->create($this->prefix().'credit_transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('pool_id')->index();
            $table->decimal('amount', 18, 6); // signed: + topup, - debit
            $table->string('type', 16); // topup|debit|adjustment
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection());
        $schema->dropIfExists($this->prefix().'credit_transactions');
        $schema->dropIfExists($this->prefix().'credit_pools');
    }
};
