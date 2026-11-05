<?php

use App\Http\Controllers\AccountController;
use Illuminate\Support\Facades\Route;

/*
| "My account" - every signed-in user. Loaded inside the authenticated group of routes/web.php.
*/

Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
Route::put('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile');
Route::put('/account/password', [AccountController::class, 'updatePassword'])
    ->middleware('throttle:5,1')
    ->name('account.password');
