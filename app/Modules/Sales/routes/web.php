<?php

use App\Modules\Sales\Http\Controllers\FormController;
use App\Modules\Sales\Http\Controllers\FormEmbedController;
use App\Modules\Sales\Http\Controllers\FormPublicController;
use Illuminate\Support\Facades\Route;

// Public form routes (accessible without login)
Route::middleware(['web'])->group(function () {
    Route::get('/f/{slug}', [FormPublicController::class, 'show'])->name('forms.public.show');
    Route::post('/f/{slug}', [FormPublicController::class, 'submit'])->name('forms.public.submit')->middleware('throttle:60,1');
    Route::get('/f/{slug}/embed.js', [FormEmbedController::class, 'script'])->name('forms.public.embed');
});

// Client app authenticated routes
Route::middleware(['web', 'client-app'])->prefix('app/sales')->name('client.sales.')->group(function () {
    // Forms Builder & Management
    Route::middleware('feature:forms')->group(function () {
        Route::get('forms/{form}/builder', [FormController::class, 'builder'])->name('forms.builder');
        Route::put('forms/{form}/builder', [FormController::class, 'updateBuilder'])->name('forms.builder.update');
        Route::get('forms/{form}/analytics', [FormController::class, 'analytics'])->name('forms.analytics');
        Route::resource('forms', FormController::class);
    });
});
