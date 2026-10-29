<?php

use Illuminate\Support\Facades\Route;

// Sales module routes: sequences, products, quotes, invoices and call logs
// are added in Phase 4. The module is auto-registered by ModuleServiceProvider.
Route::middleware(['web', 'client-app'])->prefix('app/sales')->name('client.sales.')->group(function () {
    //
});
