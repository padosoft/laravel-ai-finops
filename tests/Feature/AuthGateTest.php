<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Padosoft\LaravelAiFinOps\Tests\TestCase;

/**
 * Verifies privileged endpoints are gated by `auth_middleware` while the public
 * health probe stays open. Opts back into the default ['auth'] stack.
 */
class AuthGateTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ai-finops.routes.auth_middleware', ['auth']);
    }

    public function test_privileged_endpoint_requires_auth(): void
    {
        $this->getJson('/api/ai-finops/usage')->assertUnauthorized();
    }

    public function test_health_endpoint_stays_public(): void
    {
        $this->getJson('/api/ai-finops/health')->assertOk();
    }
}
