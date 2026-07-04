<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('documents:notify-deadlines')
    ->everyTenMinutes()
    ->withoutOverlapping();

Schedule::command('documents:send-missing-emails')
    ->weeklyOn(2, '08:00')
    ->timezone('Europe/Rome')
    ->withoutOverlapping();
