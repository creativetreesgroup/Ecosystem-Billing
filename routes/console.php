<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sessions:sweep-expired')->everyMinute()->withoutOverlapping();

// §7 minta polling tiap 45s; Laravel tidak punya preset itu (hanya kelipatan
// 5/10/15/20/30s), jadi dibulatkan ke preset terdekat — lihat DECISIONS.md.
Schedule::command('units:poll-state')->everyThirtySeconds()->withoutOverlapping()->runInBackground();
