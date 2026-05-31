<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\LaravelAiFinOpsServiceProvider;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\Pricing\PricingSourceManager;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep the suite hermetic: never hit the real LiteLLM/OpenRouter network.
        // The PricingSourceManager wraps the (fake) PricingSource binding, so a test
        // that rebinds PricingSource with its own models flows through the registry.
        $this->app->forgetInstance(PricingRegistry::class);
        $this->app->forgetInstance(PricingSourceManager::class);
        $this->app->singleton(PricingSource::class, fn () => new ArrayPricingSource);
        $this->app->singleton(PricingSourceManager::class, fn ($app) => new PricingSourceManager(
            ['litellm' => $app->make(PricingSource::class)],
            $app['config'],
        ));
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelAiFinOpsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Reach privileged endpoints without auth by default; AuthGateTest opts back in.
        $app['config']->set('ai-finops.routes.auth_middleware', []);
    }
}
