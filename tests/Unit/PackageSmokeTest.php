<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Unit;

use Padosoft\LaravelAiFinOps\LaravelAiFinOpsServiceProvider;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class PackageSmokeTest extends TestCase
{
    public function test_service_provider_is_registered(): void
    {
        $providers = $this->app->getLoadedProviders();

        $this->assertArrayHasKey(LaravelAiFinOpsServiceProvider::class, $providers);
    }

    public function test_config_is_merged_with_defaults(): void
    {
        $this->assertTrue(config('ai-finops.enabled'));
        $this->assertSame('ai_finops_', config('ai-finops.storage.table_prefix'));
        $this->assertSame(402, config('ai-finops.block_status'));
        $this->assertTrue(config('ai-finops.pricing.overrides_win'));
    }

    public function test_feature_toggles_exist(): void
    {
        $features = config('ai-finops.features');

        $this->assertIsArray($features);
        $this->assertArrayHasKey('budgets', $features);
        $this->assertArrayHasKey('policies', $features);
    }
}
