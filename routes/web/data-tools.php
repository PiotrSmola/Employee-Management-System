<?php

use App\Http\Controllers\MyExportsController;
use App\Support\Ability;
use Illuminate\Support\Facades\Route;

/*
| Data tools: "My exports" (queued bulk exports). Loaded inside the authenticated group of routes/web.php.
*/

Route::middleware('can:'.Ability::EXPORT_DATA)->prefix('exports')->name('exports.')->group(function () {
    Route::get('/', [MyExportsController::class, 'index'])->name('index');
    Route::get('/{export}/download', [MyExportsController::class, 'download'])->whereNumber('export')->name('download');
    Route::delete('/{export}', [MyExportsController::class, 'destroy'])->whereNumber('export')->name('destroy');
});
