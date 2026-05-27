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

        $schema->create($this->prefix().'price_watch_subscriptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('model', 128);
            $table->string('provider', 64)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['model', 'provider']);
        });

        $schema->create($this->prefix().'price_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('model', 128)->index();
            $table->string('provider', 64)->nullable();
            $table->decimal('input_cost_per_token', 18, 12)->default(0);
            $table->decimal('output_cost_per_token', 18, 12)->default(0);
            $table->string('source', 16)->default('litellm');
            $table->timestamp('captured_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection());
        $schema->dropIfExists($this->prefix().'price_snapshots');
        $schema->dropIfExists($this->prefix().'price_watch_subscriptions');
    }
};
