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
            // IAM delegated-access attribution: the delegation grant (`pds_dgr` claim)
            // the call ran under. Indexed — the budget guard SUMs by this key on every
            // token exchange, so the lookup must stay cheap.
            $table->string('delegation_grant_id', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('delegation_grant_id');
        });
    }
};
