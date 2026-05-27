<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Padosoft\LaravelAiFinOps\Http\Controllers\BudgetController;
use Padosoft\LaravelAiFinOps\Http\Controllers\DashboardController;
use Padosoft\LaravelAiFinOps\Http\Controllers\PricingController;
use Padosoft\LaravelAiFinOps\Http\Controllers\UsageController;

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
    });
});
