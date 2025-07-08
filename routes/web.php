<?php

use App\Http\Controllers\IndexController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', [IndexController::class, 'index'])->name('index');
Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
Route::get('/employees/{emp_no}', [EmployeeController::class, 'show'])->name('employees.show');
Route::post('/employees/export', [ExportController::class, 'export'])->name('employees.export');
Route::get('/employees/{emp_no}/export-pdf', [EmployeeController::class, 'exportShowToPdf'])->name('employees.export-show-pdf');
