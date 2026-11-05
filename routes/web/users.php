<?php

use App\Http\Controllers\UserController;
use App\Support\Ability;
use Illuminate\Support\Facades\Route;

/*
| User management (administrators). Loaded inside the authenticated group of routes/web.php.
*/

Route::middleware('can:'.Ability::MANAGE_USERS)->group(function () {
    Route::resource('users', UserController::class)->except('show');
    Route::put('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
});
