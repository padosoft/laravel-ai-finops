<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps;

use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\Contracts\UsageRecorder;
use Padosoft\LaravelAiFinOps\Ledger\DatabaseUsageRecorder;
use Padosoft\LaravelAiFinOps\Metering\MeteringListener;
use Padosoft\LaravelAiFinOps\Pricing\LiteLLMPricingSource;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;

class LaravelAiFinOpsServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../config/ai-finops.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'ai-finops');

        $this->app->singleton(UsageRecorder::class, DatabaseUsageRecorder::class);
        $this->app->singleton(PricingSource::class, LiteLLMPricingSource::class);
        $this->app->singleton(PricingRegistry::class);
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
        $this->bootMeteringHook();
    }

    /**
     * Register the single metering hook on the laravel/ai lifecycle. Guarded by
     * class_exists so the package works (metering becomes a manual no-op) when
     * laravel/ai is not installed.
     */
    private function bootMeteringHook(): void
    {
        if (! config('ai-finops.metering', true)) {
            return;
        }

        if (! config('ai-finops.hook.auto_register', true)) {
            return;
        }

        if (! class_exists(AgentPrompted::class)) {
            return;
        }

        $events = $this->app['events'];

        $events->listen(AgentPrompted::class, [MeteringListener::class, 'handleAgentPrompted']);
        $events->listen(AgentStreamed::class, [MeteringListener::class, 'handleAgentPrompted']);
        $events->listen(EmbeddingsGenerated::class, [MeteringListener::class, 'handleEmbeddingsGenerated']);
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
