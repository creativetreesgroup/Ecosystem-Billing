<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\SessionStatus;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Process;

test('two parallel requests to start a session on the same unit — exactly one succeeds', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();

    $dbEnv = [
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => config('database.connections.mysql.host'),
        'DB_PORT' => config('database.connections.mysql.port'),
        'DB_DATABASE' => config('database.connections.mysql.database'),
        'DB_USERNAME' => config('database.connections.mysql.username'),
        'DB_PASSWORD' => config('database.connections.mysql.password'),
    ];

    $command = ['php', 'artisan', 'testing:attempt-start-session', (string) $unit->id, (string) $kasir->id];

    $results = Process::pool(function ($pool) use ($command, $dbEnv) {
        $pool->command($command)->path(base_path())->env($dbEnv)->timeout(30);
        $pool->command($command)->path(base_path())->env($dbEnv)->timeout(30);
    })->wait();

    $outputs = [trim($results[0]->output()), trim($results[1]->output())];
    $successes = array_filter($outputs, fn ($line) => str_starts_with($line, 'SUCCESS:'));
    $failures = array_filter($outputs, fn ($line) => str_starts_with($line, 'FAILURE:'));

    expect($successes)->toHaveCount(1);
    expect($failures)->toHaveCount(1);
    expect(array_values($failures)[0])->toContain('UnitAlreadyActiveException');

    expect(RentalSession::where('unit_id', $unit->id)->where('status', SessionStatus::Active)->count())->toBe(1);
});
