<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Padosoft\LaravelAiFinOps\Http\Controllers\AlertController;
use Padosoft\LaravelAiFinOps\Http\Controllers\ApprovalController;
use Padosoft\LaravelAiFinOps\Http\Controllers\AuditController;
use Padosoft\LaravelAiFinOps\Http\Controllers\BudgetController;
use Padosoft\LaravelAiFinOps\Http\Controllers\ChargebackController;
use Padosoft\LaravelAiFinOps\Http\Controllers\DashboardController;
use Padosoft\LaravelAiFinOps\Http\Controllers\FootprintController;
use Padosoft\LaravelAiFinOps\Http\Controllers\ForecastController;
use Padosoft\LaravelAiFinOps\Http\Controllers\PolicyController;
use Padosoft\LaravelAiFinOps\Http\Controllers\PricingController;
use Padosoft\LaravelAiFinOps\Http\Controllers\RoutingController;
use Padosoft\LaravelAiFinOps\Http\Controllers\SettingsController;
use Padosoft\LaravelAiFinOps\Http\Controllers\UsageController;
use Padosoft\LaravelAiFinOps\Http\Controllers\WhatIfController;

/*
|--------------------------------------------------------------------------
| AI FinOps API routes
|--------------------------------------------------------------------------
| Mounted under config('ai-finops.routes.prefix') with the configured
| middleware. The public `health` probe sits in the outer group; every other
| (privileged) endpoint is additionally wrapped with `auth_middleware`.
*/

Route::group([
    'prefix' => config('ai-finops.routes.prefix', 'api/ai-finops'),
    'middleware' => config('ai-finops.routes.middleware', ['api']),
], function (): void {
    Route::get('health', fn () => response()->json([
        'package' => 'padosoft/laravel-ai-finops',
        'enabled' => (bool) config('ai-finops.enabled'),
        'metering' => (bool) config('ai-finops.metering'),
        'enforcement' => (bool) config('ai-finops.enforcement'),
    ]))->name('ai-finops.health');

    Route::group(['middleware' => config('ai-finops.routes.auth_middleware', ['auth'])], function (): void {
        // Usage / ledger
        Route::get('usage', [UsageController::class, 'index'])->name('ai-finops.usage.index');
        Route::get('usage/{id}', [UsageController::class, 'show'])->whereNumber('id')->name('ai-finops.usage.show');
        Route::get('usage/{traceId}/trace', [UsageController::class, 'trace'])->name('ai-finops.usage.trace');

        // Pricing
        Route::get('pricing/models', [PricingController::class, 'models'])->name('ai-finops.pricing.models');
        Route::post('pricing/sync', [PricingController::class, 'sync'])->name('ai-finops.pricing.sync');
        Route::get('pricing/sync/status', [PricingController::class, 'syncStatus'])->name('ai-finops.pricing.sync-status');
        Route::get('pricing/overrides', [PricingController::class, 'overrides'])->name('ai-finops.pricing.overrides');
        Route::post('pricing/overrides', [PricingController::class, 'storeOverride'])->name('ai-finops.pricing.overrides.store');
        Route::put('pricing/overrides/{id}', [PricingController::class, 'updateOverride'])->whereNumber('id')->name('ai-finops.pricing.overrides.update');
        Route::delete('pricing/overrides/{id}', [PricingController::class, 'destroyOverride'])->whereNumber('id')->name('ai-finops.pricing.overrides.destroy');

        // Budgets
        Route::get('budgets/tree', [BudgetController::class, 'tree'])->name('ai-finops.budgets.tree');
        Route::get('budgets', [BudgetController::class, 'index'])->name('ai-finops.budgets.index');
        Route::post('budgets', [BudgetController::class, 'store'])->name('ai-finops.budgets.store');
        Route::get('budgets/{id}', [BudgetController::class, 'show'])->whereNumber('id')->name('ai-finops.budgets.show');
        Route::put('budgets/{id}', [BudgetController::class, 'update'])->whereNumber('id')->name('ai-finops.budgets.update');
        Route::delete('budgets/{id}', [BudgetController::class, 'destroy'])->whereNumber('id')->name('ai-finops.budgets.destroy');
        Route::get('budgets/{id}/burndown', [BudgetController::class, 'burndown'])->whereNumber('id')->name('ai-finops.budgets.burndown');

        // Dashboard / KPIs
        Route::get('dashboard/kpis', [DashboardController::class, 'kpis'])->name('ai-finops.dashboard.kpis');
        Route::get('dashboard/spend-trend', [DashboardController::class, 'spendTrend'])->name('ai-finops.dashboard.spend-trend');
        Route::get('dashboard/top-models', [DashboardController::class, 'topModels'])->name('ai-finops.dashboard.top-models');
        Route::get('dashboard/top-tenants', [DashboardController::class, 'topTenants'])->name('ai-finops.dashboard.top-tenants');

        // Policies (declarative)
        Route::get('policies', [PolicyController::class, 'index'])->name('ai-finops.policies.index');
        Route::post('policies', [PolicyController::class, 'store'])->name('ai-finops.policies.store');
        Route::post('policies/validate', [PolicyController::class, 'validatePayload'])->name('ai-finops.policies.validate');
        Route::get('policies/{id}', [PolicyController::class, 'show'])->whereNumber('id')->name('ai-finops.policies.show');
        Route::put('policies/{id}', [PolicyController::class, 'update'])->whereNumber('id')->name('ai-finops.policies.update');
        Route::delete('policies/{id}', [PolicyController::class, 'destroy'])->whereNumber('id')->name('ai-finops.policies.destroy');
        Route::post('policies/{id}/simulate', [PolicyController::class, 'simulate'])->whereNumber('id')->name('ai-finops.policies.simulate');

        // Approvals
        Route::get('approvals', [ApprovalController::class, 'index'])->name('ai-finops.approvals.index');
        Route::get('approvals/{id}', [ApprovalController::class, 'show'])->whereNumber('id')->name('ai-finops.approvals.show');
        Route::post('approvals/{id}/approve', [ApprovalController::class, 'approve'])->whereNumber('id')->name('ai-finops.approvals.approve');
        Route::post('approvals/{id}/reject', [ApprovalController::class, 'reject'])->whereNumber('id')->name('ai-finops.approvals.reject');

        // Chargeback / showback
        Route::get('cost-centers', [ChargebackController::class, 'index'])->name('ai-finops.cost-centers.index');
        Route::post('cost-centers', [ChargebackController::class, 'store'])->name('ai-finops.cost-centers.store');
        Route::put('cost-centers/{id}', [ChargebackController::class, 'update'])->whereNumber('id')->name('ai-finops.cost-centers.update');
        Route::delete('cost-centers/{id}', [ChargebackController::class, 'destroy'])->whereNumber('id')->name('ai-finops.cost-centers.destroy');
        Route::get('chargeback/report', [ChargebackController::class, 'report'])->name('ai-finops.chargeback.report');

        // Cost-aware routing
        Route::get('routing/rules', [RoutingController::class, 'rules'])->name('ai-finops.routing.rules.index');
        Route::post('routing/rules', [RoutingController::class, 'storeRule'])->name('ai-finops.routing.rules.store');
        Route::put('routing/rules/{id}', [RoutingController::class, 'updateRule'])->whereNumber('id')->name('ai-finops.routing.rules.update');
        Route::delete('routing/rules/{id}', [RoutingController::class, 'destroyRule'])->whereNumber('id')->name('ai-finops.routing.rules.destroy');
        Route::get('routing/quality-scores', [RoutingController::class, 'qualityScores'])->name('ai-finops.routing.quality-scores');
        Route::post('routing/simulate', [RoutingController::class, 'simulate'])->name('ai-finops.routing.simulate');

        // Forecast & anomalies
        Route::get('forecast', [ForecastController::class, 'index'])->name('ai-finops.forecast.index');
        Route::get('forecast/{id}', [ForecastController::class, 'budget'])->whereNumber('id')->name('ai-finops.forecast.budget');
        Route::get('anomalies', [ForecastController::class, 'anomalies'])->name('ai-finops.anomalies.index');
        Route::post('anomalies/ack', [ForecastController::class, 'ackAnomaly'])->name('ai-finops.anomalies.ack');

        // What-if simulator
        Route::post('whatif/simulate', [WhatIfController::class, 'simulate'])->name('ai-finops.whatif.simulate');
        Route::get('whatif/scenarios', [WhatIfController::class, 'index'])->name('ai-finops.whatif.scenarios.index');
        Route::post('whatif/scenarios', [WhatIfController::class, 'store'])->name('ai-finops.whatif.scenarios.store');
        Route::get('whatif/scenarios/{id}', [WhatIfController::class, 'show'])->whereNumber('id')->name('ai-finops.whatif.scenarios.show');

        // CO2 / ESG footprint
        Route::get('footprint/summary', [FootprintController::class, 'summary'])->name('ai-finops.footprint.summary');
        Route::get('footprint/trend', [FootprintController::class, 'trend'])->name('ai-finops.footprint.trend');

        // Alerts
        Route::get('alerts/channels', [AlertController::class, 'channels'])->name('ai-finops.alerts.channels.index');
        Route::post('alerts/channels', [AlertController::class, 'storeChannel'])->name('ai-finops.alerts.channels.store');
        Route::put('alerts/channels/{id}', [AlertController::class, 'updateChannel'])->whereNumber('id')->name('ai-finops.alerts.channels.update');
        Route::delete('alerts/channels/{id}', [AlertController::class, 'destroyChannel'])->whereNumber('id')->name('ai-finops.alerts.channels.destroy');
        Route::post('alerts/channels/{id}/test', [AlertController::class, 'testChannel'])->whereNumber('id')->name('ai-finops.alerts.channels.test');
        Route::get('alerts/rules', [AlertController::class, 'rules'])->name('ai-finops.alerts.rules.index');
        Route::post('alerts/rules', [AlertController::class, 'storeRule'])->name('ai-finops.alerts.rules.store');
        Route::put('alerts/rules/{id}', [AlertController::class, 'updateRule'])->whereNumber('id')->name('ai-finops.alerts.rules.update');
        Route::delete('alerts/rules/{id}', [AlertController::class, 'destroyRule'])->whereNumber('id')->name('ai-finops.alerts.rules.destroy');
        Route::get('alerts/log', [AlertController::class, 'log'])->name('ai-finops.alerts.log');

        // Audit trail
        Route::get('audit', [AuditController::class, 'index'])->name('ai-finops.audit.index');

        // Settings / kill-switch / diagnostics
        Route::get('settings', [SettingsController::class, 'index'])->name('ai-finops.settings.index');
        Route::get('settings/kill-switch', [SettingsController::class, 'killSwitches'])->name('ai-finops.settings.kill-switch.index');
        Route::post('settings/kill-switch', [SettingsController::class, 'setKillSwitch'])->name('ai-finops.settings.kill-switch.set');
        Route::post('diagnostics/estimate', [SettingsController::class, 'estimate'])->name('ai-finops.diagnostics.estimate');
    });
});
