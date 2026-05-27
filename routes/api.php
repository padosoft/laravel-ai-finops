<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI FinOps API routes
|--------------------------------------------------------------------------
| Mounted under config('ai-finops.routes.prefix') with the configured
| middleware. Endpoints are added per macro-task (Usage/Pricing in M1,
| Budgets/Policies in M2, etc). A health probe is available from M0.
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
});
