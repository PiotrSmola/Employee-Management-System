<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Generated exports are kept for data_tools.exports.retention_days days ("My exports").
Schedule::command('exports:prune')->dailyAt('03:00')->withoutOverlapping();
