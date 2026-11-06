<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Readers get the department figures from the cache; see App\Support\FigureCache.
Schedule::command('departments:warm')->hourly()->withoutOverlapping();
