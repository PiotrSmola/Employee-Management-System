<?php

use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Middleware\EnsureTokenUserIsActive;
use App\Http\Middleware\ThrottleApi;
use App\Support\Ability;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/*
| REST API v1 (prefix /api, middleware group "api"; registered in bootstrap/app.php).
| Read-only, authenticated with Sanctum personal access tokens ("My account" -> API tokens).
| A token acts with its owner's role: the API is a bulk data interface, so it requires
| `export-data` (no viewers); manager scope and salary rules apply as in the web directory.
| Errors are JSON ({"error": {"status", "code", "message"}}). See docs/API.md.
*/

Route::prefix('v1')->name('api.v1.')->middleware([
    ThrottleApi::class,
    'auth:sanctum',
    EnsureTokenUserIsActive::class,
    CheckAbilities::class.':read',
    'can:'.Ability::EXPORT_DATA,
])->group(function () {
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/{emp_no}', [EmployeeController::class, 'show'])->whereNumber('emp_no')->name('employees.show');
    Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
});
