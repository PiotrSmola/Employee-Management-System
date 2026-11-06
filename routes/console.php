<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// These run in the scheduler (Docker: the `scheduler` service; otherwise `php artisan schedule:work`).

// Readers get the department figures from the cache; see App\Support\FigureCache.
Schedule::command('departments:warm')->hourly()->withoutOverlapping();

// Rebuild the cached report figures after a flush / midnight (no-op while they are fresh).
Schedule::command('reports:warm')->hourly()->withoutOverlapping(30);

// Generated exports are kept for data_tools.exports.retention_days days ("My exports").
Schedule::command('exports:prune')->dailyAt('03:00')->withoutOverlapping();
