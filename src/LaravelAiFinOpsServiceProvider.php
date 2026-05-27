<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps;

use Illuminate\Support\ServiceProvider;

class LaravelAiFinOpsServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../config/ai-finops.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'ai-finops');
    }

    public function boot(): void
    {
        $migrations = __DIR__.'/../database/migrations';
        if (is_dir($migrations)) {
            $this->loadMigrationsFrom($migrations);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('ai-finops.php'),
            ], 'ai-finops-config');

            if (is_dir($migrations)) {
                $this->publishes([
                    $migrations => $this->app->databasePath('migrations'),
                ], 'ai-finops-migrations');
            }
        }

        if (! config('ai-finops.enabled', true)) {
            return;
        }

        $this->bootRoutes();
    }

    private function bootRoutes(): void
    {
        if (! config('ai-finops.routes.enabled', true)) {
            return;
        }

        $routes = __DIR__.'/../routes/api.php';
        if (is_file($routes)) {
            $this->loadRoutesFrom($routes);
        }
    }
}
