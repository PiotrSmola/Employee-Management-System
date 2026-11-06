<?php

use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Middleware\EnsureTokenUserIsActive;
use Illuminate\Support\Facades\Route;

/*
| REST API v1 (prefix /api, middleware group "api"; registered in bootstrap/app.php).
| Read-only, authenticated with Sanctum personal access tokens ("My account" -> API tokens).
| A token acts with its owner's role: manager scope and salary rules apply as in the web
| directory. Errors are JSON ({"error": {"status", "code", "message"}}). See docs/API.md.
*/

Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', EnsureTokenUserIsActive::class, 'throttle:api'])->group(function () {
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/{emp_no}', [EmployeeController::class, 'show'])->whereNumber('emp_no')->name('employees.show');
    Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
});
