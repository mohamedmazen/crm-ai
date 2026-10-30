<?php

use App\Modules\Sales\Http\Controllers\BookingPublicController;
use App\Modules\Sales\Http\Controllers\FormController;
use App\Modules\Sales\Http\Controllers\FormEmbedController;
use App\Modules\Sales\Http\Controllers\FormPublicController;
use App\Modules\Sales\Http\Controllers\LeadSourceController;
use App\Modules\Sales\Http\Controllers\MeetingTypeController;
use App\Modules\Sales\Http\Controllers\TrackerScriptController;
use Illuminate\Support\Facades\Route;

// Public form routes, booking routes, and tracker script (accessible without login)
Route::middleware(['web'])->group(function () {
    Route::get('/tracker.js', [TrackerScriptController::class, 'script'])->name('tracker.script');
    Route::get('/f/{slug}', [FormPublicController::class, 'show'])->name('forms.public.show');
    Route::post('/f/{slug}', [FormPublicController::class, 'submit'])->name('forms.public.submit')->middleware('throttle:60,1');
    Route::get('/f/{slug}/embed.js', [FormEmbedController::class, 'script'])->name('forms.public.embed');

    // Public Booking
    Route::get('/b/{slug}', [BookingPublicController::class, 'show'])->name('booking.public.show');
    Route::get('/b/{slug}/slots', [BookingPublicController::class, 'slots'])->name('booking.public.slots');
    Route::post('/b/{slug}', [BookingPublicController::class, 'store'])->name('booking.public.submit')->middleware('throttle:30,1');
    Route::get('/b/{slug}/confirmed/{uuid}', [BookingPublicController::class, 'confirmed'])->name('booking.public.confirmed');
    Route::post('/b/cancel/{token}', [BookingPublicController::class, 'cancel'])->name('booking.public.cancel');
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

    // Lead Sources & Attribution
    Route::middleware('feature:lead_sources')->group(function () {
        Route::post('lead-sources/{lead_source}/regenerate-token', [LeadSourceController::class, 'regenerateToken'])->name('lead-sources.regenerate-token');
        Route::resource('lead-sources', LeadSourceController::class)->only(['index', 'store', 'update', 'destroy']);
    });

    // Booking Pages & Appointments
    Route::middleware('feature:booking')->group(function () {
        Route::put('booking/availability', [MeetingTypeController::class, 'updateAvailability'])->name('booking.availability.update');
        Route::post('booking/bookings/{booking}/cancel', [MeetingTypeController::class, 'cancelBooking'])->name('booking.bookings.cancel');
        Route::resource('booking', MeetingTypeController::class)->parameters([
            'booking' => 'meetingType',
        ])->names([
            'index' => 'booking.index',
            'store' => 'booking.store',
            'update' => 'booking.update',
            'destroy' => 'booking.destroy',
        ]);
    });
});
