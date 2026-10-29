<?php

use Illuminate\Support\Facades\Route;

// Reporting module routes: the sales reports (pipeline, volume, sources,
// win/loss, agents and response time) are added in Phase 3. The module is
// auto-registered by ModuleServiceProvider.
Route::middleware(['web', 'client-app'])->prefix('app/reports/sales')->name('client.reports.sales.')->group(function () {
    //
});
