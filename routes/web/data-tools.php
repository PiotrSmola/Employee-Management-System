<?php

use App\Http\Controllers\EmployeeImportController;
use App\Http\Controllers\MyExportsController;
use App\Support\Ability;
use Illuminate\Support\Facades\Route;

/*
| Data tools: "My exports" (queued bulk exports) and the employee import.
| Loaded inside the authenticated group of routes/web.php.
*/

Route::middleware('can:'.Ability::EXPORT_DATA)->prefix('exports')->name('exports.')->group(function () {
    Route::get('/', [MyExportsController::class, 'index'])->name('index');
    Route::get('/{export}/download', [MyExportsController::class, 'download'])->whereNumber('export')->name('download');
    Route::delete('/{export}', [MyExportsController::class, 'destroy'])->whereNumber('export')->name('destroy');
});

Route::middleware('can:'.Ability::IMPORT_DATA)->prefix('employees/import')->name('employees.import.')->group(function () {
    Route::get('/', [EmployeeImportController::class, 'create'])->name('create');
    Route::get('/template.{format}', [EmployeeImportController::class, 'template'])->whereIn('format', ['csv', 'xlsx'])->name('template');
    Route::post('/', [EmployeeImportController::class, 'upload'])->middleware('throttle:20,1')->name('upload');
    Route::get('/{token}', [EmployeeImportController::class, 'preview'])->whereUuid('token')->name('preview');
    Route::post('/{token}', [EmployeeImportController::class, 'commit'])->whereUuid('token')->name('commit');
    Route::delete('/{token}', [EmployeeImportController::class, 'cancel'])->whereUuid('token')->name('cancel');
});
