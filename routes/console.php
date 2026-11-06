<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Readers get the department figures from the cache; see App\Support\FigureCache.
Schedule::command('departments:warm')->hourly()->withoutOverlapping();

// Rebuild the cached report figures after a flush / expiry (no-op while they are cached).
Schedule::command('reports:warm')->hourly()->withoutOverlapping(30);
