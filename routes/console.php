<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sessions:sweep-expired')->everyMinute()->withoutOverlapping();

// §7 minta polling tiap 45s; Laravel tidak punya preset itu (hanya kelipatan
// 5/10/15/20/30s), jadi dibulatkan ke preset terdekat — lihat DECISIONS.md.
Schedule::command('units:poll-state')->everyThirtySeconds()->withoutOverlapping()->runInBackground();
