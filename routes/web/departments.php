<?php

use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DepartmentManagerController;
use App\Support\Ability;
use Illuminate\Support\Facades\Route;

/*
| Departments: index + page for everyone who can see employees (managers: own
| department only, DepartmentPolicy::view), changes behind `manage-departments`.
| Loaded inside the authenticated group of routes/web.php. {department} is the
| dept_no ("d" + three digits), so /departments/create is not a department.
*/

Route::prefix('departments')->name('departments.')->where(['department' => 'd[0-9]{3}'])->group(function () {
    Route::get('/', [DepartmentController::class, 'index'])->name('index');

    Route::middleware('can:'.Ability::MANAGE_DEPARTMENTS)->group(function () {
        Route::get('/create', [DepartmentController::class, 'create'])->name('create');
        Route::post('/', [DepartmentController::class, 'store'])->name('store');
        Route::get('/{department}/edit', [DepartmentController::class, 'edit'])->name('edit');
        Route::put('/{department}', [DepartmentController::class, 'update'])->name('update');
        Route::delete('/{department}', [DepartmentController::class, 'destroy'])->name('destroy');

        Route::get('/{department}/manager-candidates', [DepartmentManagerController::class, 'candidates'])
            ->middleware('throttle:60,1')
            ->name('managers.candidates');
        Route::post('/{department}/managers', [DepartmentManagerController::class, 'store'])->name('managers.store');
        Route::post('/{department}/managers/end', [DepartmentManagerController::class, 'end'])->name('managers.end');
    });

    Route::get('/{department}', [DepartmentController::class, 'show'])->name('show');
});
