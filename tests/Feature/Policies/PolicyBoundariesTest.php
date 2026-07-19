<?php

use App\Models\DeviceAlert;
use App\Models\Package;
use App\Models\RentalSession;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;

beforeEach(function () {
    $this->kasir = User::factory()->create();
    $this->owner = User::factory()->owner()->create();
});

test('kasir cannot browse unit types, packages, rental sessions, or settings', function () {
    expect($this->kasir->can('viewAny', UnitType::class))->toBeFalse()
        ->and($this->kasir->can('viewAny', Package::class))->toBeFalse()
        ->and($this->kasir->can('viewAny', RentalSession::class))->toBeFalse()
        ->and($this->kasir->can('viewAny', Setting::class))->toBeFalse();
});

test('owner can browse unit types, packages, rental sessions, and settings', function () {
    expect($this->owner->can('viewAny', UnitType::class))->toBeTrue()
        ->and($this->owner->can('viewAny', Package::class))->toBeTrue()
        ->and($this->owner->can('viewAny', RentalSession::class))->toBeTrue()
        ->and($this->owner->can('viewAny', Setting::class))->toBeTrue();
});

test('only owner can void a rental session', function () {
    $session = RentalSession::factory()->create();

    expect($this->kasir->can('void', $session))->toBeFalse()
        ->and($this->owner->can('void', $session))->toBeTrue();
});

test('only owner can manage units, kasir can only view them', function () {
    $unit = Unit::factory()->create();

    expect($this->kasir->can('viewAny', Unit::class))->toBeTrue()
        ->and($this->kasir->can('create', Unit::class))->toBeFalse()
        ->and($this->kasir->can('update', $unit))->toBeFalse()
        ->and($this->owner->can('create', Unit::class))->toBeTrue()
        ->and($this->owner->can('update', $unit))->toBeTrue();
});

test('both kasir and owner can acknowledge device alerts', function () {
    $alert = DeviceAlert::factory()->create();

    expect($this->kasir->can('acknowledge', $alert))->toBeTrue()
        ->and($this->owner->can('acknowledge', $alert))->toBeTrue();
});

test('no one creates rental sessions or device alerts directly through a form', function () {
    expect($this->kasir->can('create', RentalSession::class))->toBeFalse()
        ->and($this->owner->can('create', RentalSession::class))->toBeFalse()
        ->and($this->kasir->can('create', DeviceAlert::class))->toBeFalse()
        ->and($this->owner->can('create', DeviceAlert::class))->toBeFalse();
});

test('only owner can manage user accounts', function () {
    expect($this->kasir->can('viewAny', User::class))->toBeFalse()
        ->and($this->kasir->can('create', User::class))->toBeFalse()
        ->and($this->owner->can('viewAny', User::class))->toBeTrue()
        ->and($this->owner->can('create', User::class))->toBeTrue();
});

test('nobody can delete a user account, since sessions reference it', function () {
    expect($this->owner->can('delete', $this->kasir))->toBeFalse()
        ->and($this->kasir->can('delete', $this->owner))->toBeFalse();
});
