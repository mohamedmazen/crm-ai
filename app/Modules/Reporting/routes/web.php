<?php

use App\Modules\Reporting\Http\Controllers\ReportScheduleController;
use App\Modules\Reporting\Http\Controllers\SalesReportController;
use Illuminate\Support\Facades\Route;

// Sales reports (Phase 3): the eight reports, their CSV exports and the
// scheduled email delivery. The whole group is gated on the plan feature.
Route::middleware(['web', 'client-app', 'feature:sales_reports'])
    ->prefix('app/reports/sales')
    ->name('client.reports.sales.')
    ->group(function () {
        Route::get('/', [SalesReportController::class, 'index'])->name('index');
        Route::get('volume', [SalesReportController::class, 'volume'])->name('volume');
        Route::get('sources', [SalesReportController::class, 'sources'])->name('sources');
        Route::get('win-loss', [SalesReportController::class, 'winLoss'])->name('win-loss');
        Route::get('agents', [SalesReportController::class, 'agents'])->name('agents');
        Route::get('response', [SalesReportController::class, 'response'])->name('response');
        Route::get('pipeline', [SalesReportController::class, 'pipeline'])->name('pipeline');
        Route::get('attribution', [SalesReportController::class, 'attribution'])->name('attribution');
        Route::get('export/{report}', [SalesReportController::class, 'export'])
            ->whereIn('report', ['volume', 'sources', 'win-loss', 'agents', 'response', 'pipeline', 'attribution'])
            ->name('export');

        Route::get('schedules', [ReportScheduleController::class, 'index'])->name('schedules');
        Route::post('schedules', [ReportScheduleController::class, 'store'])->name('schedules.store');
        Route::put('schedules/{schedule}', [ReportScheduleController::class, 'update'])->name('schedules.update');
        Route::delete('schedules/{schedule}', [ReportScheduleController::class, 'destroy'])->name('schedules.destroy');
    });
