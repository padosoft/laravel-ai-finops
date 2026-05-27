<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Padosoft\LaravelAiFinOps\Tests\TestCase;

class ConfigPublishingTest extends TestCase
{
    public function test_package_boots_within_a_laravel_application(): void
    {
        $this->assertTrue($this->app->bound('config'));
        $this->assertIsArray(config('ai-finops'));
    }

    public function test_routes_prefix_is_configurable(): void
    {
        $this->assertSame('api/ai-finops', config('ai-finops.routes.prefix'));
    }
}
