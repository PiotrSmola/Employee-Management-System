<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\EmployeeOperationsController;
use App\Support\Ability;
use Illuminate\Support\Facades\Route;

/*
| HR operations on employees (hire, personal data, transfer, title / salary changes,
| termination, rehire, corrections) and the audit log. Loaded inside the
| authenticated group of routes/web.php; see docs/HR_OPERATIONS.md.
*/

Route::middleware('can:'.Ability::MANAGE_EMPLOYEES)->group(function () {
    Route::get('/employees/create', [EmployeeOperationsController::class, 'create'])->name('employees.create');
    Route::post('/employees', [EmployeeOperationsController::class, 'store'])->name('employees.store');

    Route::prefix('/employees/{emp_no}')->whereNumber('emp_no')->name('employees.')->group(function () {
        Route::put('/', [EmployeeOperationsController::class, 'update'])->name('update');
        Route::post('/transfer', [EmployeeOperationsController::class, 'transfer'])->name('transfer');
        Route::post('/title', [EmployeeOperationsController::class, 'changeTitle'])->name('title');
        Route::post('/salary', [EmployeeOperationsController::class, 'changeSalary'])->name('salary');
        Route::post('/terminate', [EmployeeOperationsController::class, 'terminate'])->name('terminate');
        Route::post('/rehire', [EmployeeOperationsController::class, 'rehire'])->name('rehire');
        Route::put('/salary/latest', [EmployeeOperationsController::class, 'correctSalary'])->name('salary.correct');
        Route::put('/title/latest', [EmployeeOperationsController::class, 'correctTitle'])->name('title.correct');
    });
});

Route::get('/audit-log', [AuditLogController::class, 'index'])
    ->middleware('can:'.Ability::VIEW_AUDIT_LOG)
    ->name('audit-log.index');
