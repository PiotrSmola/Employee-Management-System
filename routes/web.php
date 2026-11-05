<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\IndexController;
use Illuminate\Support\Facades\Route;

/*
| Everything except the login form requires an active, signed-in user
| (`auth` + `active`; `auth.session` signs out other sessions after a password
| change). Livewire update requests use the same middleware (AppServiceProvider).
| Abilities: App\Support\Ability, see docs/AUTHORIZATION.md.
|
| Feature routes live in routes/web/<feature>.php: every file there is loaded
| (in file name order) inside the authenticated group below, so a new feature
| adds its own file instead of editing this one (docs/EXTENDING.md).
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'auth.session', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', [IndexController::class, 'index'])->name('index');

    $files = glob(__DIR__.'/web/*.php') ?: [];
    sort($files);

    foreach ($files as $file) {
        require $file;
    }
});
