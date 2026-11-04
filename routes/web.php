<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\IndexController;
use App\Http\Controllers\UserController;
use App\Support\Ability;
use Illuminate\Support\Facades\Route;

/*
| Everything except the login form requires an active, signed-in user
| (`auth` + `active`; `auth.session` signs out other sessions after a password
| change). Livewire update requests use the same middleware (AppServiceProvider).
| Abilities: App\Support\Ability, see docs/AUTHORIZATION.md.
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'auth.session', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', [IndexController::class, 'index'])->name('index');

    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('/employees/export', [ExportController::class, 'export'])
        ->middleware('can:'.Ability::EXPORT_DATA)
        ->name('employees.export');
    Route::get('/employees/{emp_no}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::get('/employees/{emp_no}/export-pdf', [EmployeeController::class, 'exportShowToPdf'])->name('employees.export-show-pdf');

    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');

    Route::middleware('can:'.Ability::MANAGE_USERS)->group(function () {
        Route::resource('users', UserController::class)->except('show');
        Route::put('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
    });
});
