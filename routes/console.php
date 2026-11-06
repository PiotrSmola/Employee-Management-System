<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Rebuild the cached report figures after a flush / expiry (no-op while they are cached).
Schedule::command('reports:warm')->hourly()->withoutOverlapping();
