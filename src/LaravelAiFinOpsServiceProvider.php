<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps;

use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\PromptingAgent;
use Padosoft\LaravelAiFinOps\Console\PruneLedgerCommand;
use Padosoft\LaravelAiFinOps\Console\ReportCommand;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\Contracts\UsageRecorder;
use Padosoft\LaravelAiFinOps\Ledger\DatabaseUsageRecorder;
use Padosoft\LaravelAiFinOps\Metering\MeteringListener;
use Padosoft\LaravelAiFinOps\Policies\EnforcementListener;
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

            $this->commands([
                ReportCommand::class,
                PruneLedgerCommand::class,
            ]);
        }

        if (! config('ai-finops.enabled', true)) {
            return;
        }

        $this->bootRoutes();
        $this->bootMeteringHook();
        $this->bootEnforcementHook();
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

    /**
     * Register pre-flight enforcement on the laravel/ai lifecycle. Kill switches
     * apply even when budget enforcement is off (the PolicyEngine decides), so this
     * is registered whenever the hook is active and laravel/ai is present.
     */
    private function bootEnforcementHook(): void
    {
        if (! config('ai-finops.hook.auto_register', true)) {
            return;
        }

        if (! class_exists(PromptingAgent::class)) {
            return;
        }

        $events = $this->app['events'];

        $events->listen(PromptingAgent::class, [EnforcementListener::class, 'handlePromptingAgent']);
        $events->listen(GeneratingEmbeddings::class, [EnforcementListener::class, 'handleGeneratingEmbeddings']);
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
