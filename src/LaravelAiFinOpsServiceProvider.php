<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps;

use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\PromptingAgent;
use Padosoft\LaravelAiFinOps\Audit\AuditObserver;
use Padosoft\LaravelAiFinOps\Console\CapturePricesCommand;
use Padosoft\LaravelAiFinOps\Console\CheckAlertsCommand;
use Padosoft\LaravelAiFinOps\Console\PruneLedgerCommand;
use Padosoft\LaravelAiFinOps\Console\ReportCommand;
use Padosoft\LaravelAiFinOps\Contracts\CopilotProvider;
use Padosoft\LaravelAiFinOps\Contracts\GuardrailProvider;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\Contracts\QualityScoreProvider;
use Padosoft\LaravelAiFinOps\Contracts\UsageRecorder;
use Padosoft\LaravelAiFinOps\Copilot\NullCopilotProvider;
use Padosoft\LaravelAiFinOps\Guardrails\NullGuardrailProvider;
use Padosoft\LaravelAiFinOps\Ledger\DatabaseUsageRecorder;
use Padosoft\LaravelAiFinOps\Metering\MeteringListener;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Padosoft\LaravelAiFinOps\Models\CostCenter;
use Padosoft\LaravelAiFinOps\Models\KillSwitch;
use Padosoft\LaravelAiFinOps\Models\Policy;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Padosoft\LaravelAiFinOps\Models\SpendApproval;
use Padosoft\LaravelAiFinOps\Models\SubscriptionWindow;
use Padosoft\LaravelAiFinOps\Policies\EnforcementListener;
use Padosoft\LaravelAiFinOps\Pricing\LiteLLMPricingSource;
use Padosoft\LaravelAiFinOps\Pricing\ManualPricingSource;
use Padosoft\LaravelAiFinOps\Pricing\OpenRouterPricingSource;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\Pricing\PricingSourceManager;
use Padosoft\LaravelAiFinOps\Routing\NullQualityScoreProvider;
use Padosoft\LaravelAiFinOps\Support\TraceContext;

class LaravelAiFinOpsServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../config/ai-finops.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'ai-finops');

        // Scoped (not singleton): reset at each request/job boundary so a worker
        // (Octane/Swoole/queue) never bleeds one run's trace/tenant into the next.
        $this->app->scoped(TraceContext::class);
        $this->app->singleton(UsageRecorder::class, DatabaseUsageRecorder::class);
        // Back-compat: the bare PricingSource binding stays the LiteLLM base.
        $this->app->singleton(PricingSource::class, LiteLLMPricingSource::class);

        // The manager owns every source; PricingRegistry resolves through it.
        $this->app->singleton(PricingSourceManager::class, fn ($app) => new PricingSourceManager([
            'litellm' => $app->make(LiteLLMPricingSource::class),
            'openrouter' => $app->make(OpenRouterPricingSource::class),
            'manual' => $app->make(ManualPricingSource::class),
        ], $app['config']));

        $this->app->singleton(PricingRegistry::class);

        // Seam for eval-harness quality scores; host binds a real adapter when wired.
        $this->app->singleton(
            QualityScoreProvider::class,
            NullQualityScoreProvider::class,
        );

        // Seam for pii-redactor / ai-act-compliance guardrails (toggle gated).
        $this->app->singleton(
            GuardrailProvider::class,
            NullGuardrailProvider::class,
        );

        // Seam for the FinOps copilot (laravel-ai-chat / AskMyDocs).
        $this->app->singleton(
            CopilotProvider::class,
            NullCopilotProvider::class,
        );
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
                CheckAlertsCommand::class,
                CapturePricesCommand::class,
            ]);
        }

        if (! config('ai-finops.enabled', true)) {
            return;
        }

        $this->bootRoutes();
        $this->bootMeteringHook();
        $this->bootEnforcementHook();
        $this->bootAuditObservers();
    }

    /** Observe governance models so mutations land in the audit log. */
    private function bootAuditObservers(): void
    {
        if (! config('ai-finops.audit.enabled', true)) {
            return;
        }

        foreach ([
            Budget::class,
            Policy::class,
            KillSwitch::class,
            CostCenter::class,
            SpendApproval::class,
            PricingOverride::class,
            SubscriptionWindow::class,
        ] as $model) {
            $model::observe(AuditObserver::class);
        }
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
