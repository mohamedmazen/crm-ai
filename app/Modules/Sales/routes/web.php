<?php

use App\Modules\Sales\Http\Controllers\AiSdrController;
use App\Modules\Sales\Http\Controllers\BookingPublicController;
use App\Modules\Sales\Http\Controllers\CallAiController;
use App\Modules\Sales\Http\Controllers\CallController;
use App\Modules\Sales\Http\Controllers\FormController;
use App\Modules\Sales\Http\Controllers\FormEmbedController;
use App\Modules\Sales\Http\Controllers\FormPublicController;
use App\Modules\Sales\Http\Controllers\InvoiceController;
use App\Modules\Sales\Http\Controllers\InvoicePublicController;
use App\Modules\Sales\Http\Controllers\LeadSourceController;
use App\Modules\Sales\Http\Controllers\MeetingTypeController;
use App\Modules\Sales\Http\Controllers\ProductController;
use App\Modules\Sales\Http\Controllers\QuoteController;
use App\Modules\Sales\Http\Controllers\QuotePublicController;
use App\Modules\Sales\Http\Controllers\SequenceController;
use App\Modules\Sales\Http\Controllers\SequenceUnsubscribeController;
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
    Route::get('/b/cancel/{token}', [BookingPublicController::class, 'cancelShow'])->name('booking.public.cancel.show');

    // Reschedule (reached from the guest's confirmation email). Declared with a
    // literal prefix so it is not mistaken for a meeting-type slug.
    Route::get('/b/reschedule/{token}', [BookingPublicController::class, 'rescheduleShow'])->name('booking.public.reschedule.show');
    Route::post('/b/reschedule/{token}', [BookingPublicController::class, 'reschedule'])->name('booking.public.reschedule')->middleware('throttle:30,1');

    // One-click unsubscribe for sequence emails (signed URL, no auth).
    Route::get('/u/sequence/{enrollment}', [SequenceUnsubscribeController::class, 'unsubscribe'])
        ->name('sequences.unsubscribe')
        ->middleware('signed');

    // Public quotes & invoices. The long token is the only credential.
    Route::get('/q/{token}', [QuotePublicController::class, 'show'])->name('quotes.public.show');
    Route::post('/q/{token}/accept', [QuotePublicController::class, 'accept'])->name('quotes.public.accept')->middleware('throttle:20,1');
    Route::post('/q/{token}/reject', [QuotePublicController::class, 'reject'])->name('quotes.public.reject')->middleware('throttle:20,1');
    Route::get('/q/{token}/pdf', [QuotePublicController::class, 'pdf'])->name('quotes.public.pdf');

    Route::get('/i/{token}', [InvoicePublicController::class, 'show'])->name('invoices.public.show');
    Route::get('/i/{token}/pdf', [InvoicePublicController::class, 'pdf'])->name('invoices.public.pdf');
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

    // Email / WhatsApp sequences
    Route::middleware('feature:sequences')->group(function () {
        Route::post('sequences/{sequence}/status', [SequenceController::class, 'updateStatus'])->name('sequences.status');
        Route::post('sequences/{sequence}/enroll', [SequenceController::class, 'enroll'])->name('sequences.enroll');
        Route::get('sequences/{sequence}/builder', [SequenceController::class, 'builder'])->name('sequences.builder');
        Route::put('sequences/{sequence}/builder', [SequenceController::class, 'updateBuilder'])->name('sequences.builder.update');
        Route::resource('sequences', SequenceController::class)->only(['index', 'store', 'destroy']);
    });

    // AI SDR: autonomous follow-ups until a human replies.
    Route::middleware('feature:ai_sdr')->group(function () {
        Route::get('ai-sdr', [AiSdrController::class, 'index'])->name('ai-sdr.index');
        Route::put('ai-sdr', [AiSdrController::class, 'update'])->name('ai-sdr.update');
        Route::post('ai-sdr/run', [AiSdrController::class, 'run'])->name('ai-sdr.run');
    });

    // Call log + click-to-call
    Route::middleware('feature:call_logs')->group(function () {
        Route::post('calls/initiate', [CallController::class, 'initiate'])->name('calls.initiate');
        Route::post('calls/settings', [CallController::class, 'updateSettings'])->name('calls.settings.update');
        Route::resource('calls', CallController::class)->only(['index', 'store', 'destroy']);

        // Summarising is the metered part; storing a transcript is an ordinary edit.
        Route::post('calls/{call}/summary', [CallAiController::class, 'summarise'])
            ->name('calls.summary')
            ->middleware(['feature:ai_sales', 'limit:ai_tokens_per_month,ai_tokens']);
    });

    // Products, quotes and invoices
    Route::middleware('feature:quotes')->group(function () {
        Route::resource('products', ProductController::class)->only(['index', 'store', 'update', 'destroy']);

        Route::post('quotes/{quote}/send', [QuoteController::class, 'send'])->name('quotes.send');
        Route::post('quotes/{quote}/accept', [QuoteController::class, 'accept'])->name('quotes.accept');
        Route::post('quotes/{quote}/reject', [QuoteController::class, 'reject'])->name('quotes.reject');
        Route::post('quotes/{quote}/convert', [QuoteController::class, 'convert'])->name('quotes.convert');
        Route::get('quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->name('quotes.pdf');
        Route::resource('quotes', QuoteController::class);

        Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
        Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'payment'])->name('invoices.payments.store');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
        Route::resource('invoices', InvoiceController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
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
