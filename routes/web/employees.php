<?php

use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ExportController;
use App\Support\Ability;
use Illuminate\Support\Facades\Route;

/*
| Employee directory and profile. Loaded inside the authenticated group of routes/web.php.
| {emp_no} only matches numbers, so other features can add /employees/<word> routes in their own files.
*/

Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
Route::post('/employees/export', [ExportController::class, 'export'])
    ->middleware('can:'.Ability::EXPORT_DATA)
    ->name('employees.export');
Route::get('/employees/{emp_no}', [EmployeeController::class, 'show'])
    ->whereNumber('emp_no')
    ->name('employees.show');
Route::get('/employees/{emp_no}/export-pdf', [EmployeeController::class, 'exportShowToPdf'])
    ->whereNumber('emp_no')
    ->name('employees.export-show-pdf');
