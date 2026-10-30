<?php

use App\Modules\Leads\Http\Controllers\LeadBulkActionController;
use App\Modules\Leads\Http\Controllers\LeadController;
use App\Modules\Leads\Http\Controllers\LeadExportController;
use App\Modules\Leads\Http\Controllers\LeadImportController;
use App\Modules\Leads\Http\Controllers\LeadPipelineController;
use App\Modules\Leads\Http\Controllers\LeadTaskController;
use App\Modules\Leads\Http\Controllers\PipelineSettingsController;
use App\Modules\Leads\Http\Controllers\TaskDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/leads')->name('client.leads.')->group(function () {
    Route::get('/', [LeadController::class, 'index'])->name('index');
    Route::post('/scrape', [LeadController::class, 'scrape'])->name('scrape')->middleware('limit:lead_credits_per_month,lead_credits');
    Route::post('/push-to-contacts', [LeadController::class, 'pushToContacts'])->name('push-to-contacts');

    // Pipeline board. Declared before /{lead} so "pipeline" is not swallowed as an id.
    Route::prefix('pipeline')->name('pipeline.')->group(function () {
        Route::get('/', [LeadPipelineController::class, 'index'])->name('index');
        Route::post('/rescore', [LeadPipelineController::class, 'rescore'])->name('rescore');
        Route::put('/scoring', [LeadPipelineController::class, 'updateScoring'])->name('scoring.update');
        Route::post('/stages', [LeadPipelineController::class, 'storeStage'])->name('stages.store');
        Route::put('/stages/reorder', [LeadPipelineController::class, 'reorderStages'])->name('stages.reorder');
        Route::put('/stages/{stage}', [LeadPipelineController::class, 'updateStage'])->name('stages.update');
        Route::delete('/stages/{stage}', [LeadPipelineController::class, 'destroyStage'])->name('stages.destroy');
        Route::put('/{lead}/move', [LeadPipelineController::class, 'move'])->name('move');
        Route::put('/{lead}/deal', [LeadPipelineController::class, 'updateDeal'])->name('deal.update');
        Route::put('/{lead}/assign', [LeadPipelineController::class, 'assign'])->name('assign');
        Route::post('/{lead}/activities', [LeadPipelineController::class, 'storeActivity'])->name('activities.store');
    });

    // Pipeline settings and multiple pipelines management
    Route::prefix('pipelines')->name('pipelines.')->group(function () {
        Route::get('/', [PipelineSettingsController::class, 'index'])->name('index');
        Route::post('/', [PipelineSettingsController::class, 'store'])->name('store')->middleware('feature:multi_pipeline');
        Route::put('/{pipeline}', [PipelineSettingsController::class, 'update'])->name('update');
        Route::delete('/{pipeline}', [PipelineSettingsController::class, 'destroy'])->name('destroy');
        Route::post('/reasons', [PipelineSettingsController::class, 'storeReason'])->name('reasons.store');
        Route::delete('/reasons/{reason}', [PipelineSettingsController::class, 'destroyReason'])->name('reasons.destroy');
    });

    // Lead tasks
    Route::post('/{lead}/tasks', [LeadTaskController::class, 'store'])->name('tasks.store');
    Route::put('/tasks/{task}', [LeadTaskController::class, 'update'])->name('tasks.update');
    Route::patch('/tasks/{task}/toggle', [LeadTaskController::class, 'toggle'])->name('tasks.toggle');
    Route::delete('/tasks/{task}', [LeadTaskController::class, 'destroy'])->name('tasks.destroy');

    // Lead custom fields
    Route::put('/{lead}/custom-fields', [LeadPipelineController::class, 'updateCustomFields'])->name('custom-fields.update');

    // Bulk actions
    Route::post('/bulk-assign', [LeadBulkActionController::class, 'assign'])->name('bulk.assign');
    Route::post('/bulk-stage', [LeadBulkActionController::class, 'moveStage'])->name('bulk.stage');
    Route::post('/bulk-tags', [LeadBulkActionController::class, 'tags'])->name('bulk.tags');
    Route::post('/bulk-delete', [LeadBulkActionController::class, 'destroy'])->name('bulk.destroy');

    // Import and Export
    Route::get('/export', [LeadExportController::class, 'export'])->name('export');
    Route::post('/import', [LeadImportController::class, 'import'])->name('import');

    Route::delete('/{lead}', [LeadController::class, 'destroy'])->name('destroy');
});

// Tasks dashboard
Route::middleware(['web', 'client-app'])->prefix('app/tasks')->name('client.tasks.')->group(function () {
    Route::get('/', [TaskDashboardController::class, 'index'])->name('index');
});
