<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('images:cron --retina --delete-sources')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

    // * * * * * php /path/to/project/artisan schedule:run >> /dev/null 2>&1
