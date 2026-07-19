<?php

use App\Domain\Sessions\SessionStatus;
use App\Models\RentalSession;
use App\Models\Unit;
use Illuminate\Database\QueryException;

test('migrations run against the mysql test database and factories work', function () {
    $unit = Unit::factory()->create();

    expect($unit)->toBeInstanceOf(Unit::class);
});

test('a unit cannot have two active rental sessions at once', function () {
    $unit = Unit::factory()->create();

    RentalSession::factory()->for($unit)->create(['status' => SessionStatus::Active]);

    RentalSession::factory()->for($unit)->create(['status' => SessionStatus::Active]);
})->throws(QueryException::class);
