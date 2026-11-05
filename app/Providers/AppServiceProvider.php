<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // No uncompromised() check: the application may run without internet access.
        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        // Livewire component updates (sorting, paging, filters, ...) need the
        // same signed-in, active user as the pages that render the components.
        Livewire::setUpdateRoute(fn ($handle) => Route::post('/livewire/update', $handle)
            ->middleware(['web', 'auth', 'auth.session', 'active']));
    }
}
