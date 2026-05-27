<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Padosoft\LaravelAiFinOps\Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_reports_package_status(): void
    {
        $this->getJson('/api/ai-finops/health')
            ->assertOk()
            ->assertJson([
                'package' => 'padosoft/laravel-ai-finops',
                'enabled' => true,
            ]);
    }
}
