<?php

use App\Http\Controllers\ReportController;
use App\Support\Ability;
use Illuminate\Support\Facades\Route;

/*
| Reports (admin, HR, managers - managers only see their own department).
| Loaded inside the authenticated group of routes/web.php. Salary reports
| additionally require view-salaries (route middleware / ReportController::csv).
*/

Route::middleware('can:'.Ability::VIEW_REPORTS)->prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('/workforce', [ReportController::class, 'workforce'])->name('workforce');
    Route::get('/compensation', [ReportController::class, 'compensation'])
        ->middleware('can:'.Ability::VIEW_SALARIES)
        ->name('compensation');
    Route::get('/{report}/csv', [ReportController::class, 'csv'])
        ->where('report', '[a-z-]+')
        ->name('csv');
});
