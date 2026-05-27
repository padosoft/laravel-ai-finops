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

        $schema->create($this->prefix().'alert_channels', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('type', 16); // mail|slack|teams|webhook|sms
            $table->string('name');
            $table->json('config')->nullable(); // endpoint/recipient/etc (secret — never returned raw)
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        $schema->create($this->prefix().'alert_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->unsignedBigInteger('budget_id')->index();
            $table->unsignedTinyInteger('threshold_pct'); // 1..100+
            $table->unsignedBigInteger('channel_id')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedSmallInteger('last_notified_pct')->nullable();
            $table->timestamps();
        });

        $schema->create($this->prefix().'alert_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('rule_id')->index();
            $table->unsignedBigInteger('budget_id');
            $table->decimal('percent', 8, 4);
            $table->decimal('spent', 18, 8);
            $table->string('message');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection());
        $schema->dropIfExists($this->prefix().'alert_log');
        $schema->dropIfExists($this->prefix().'alert_rules');
        $schema->dropIfExists($this->prefix().'alert_channels');
    }
};
