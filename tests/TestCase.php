<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\LaravelAiFinOps\LaravelAiFinOpsServiceProvider;

abstract class TestCase extends Orchestra
{
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
