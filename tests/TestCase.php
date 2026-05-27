<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\LaravelAiFinOpsServiceProvider;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep the suite hermetic: never hit the real LiteLLM network mirror.
        // Tests that need prices construct their own PricingRegistry/source.
        $this->app->forgetInstance(PricingRegistry::class);
        $this->app->singleton(PricingSource::class, fn () => new ArrayPricingSource);
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
    }
}
