<?php

use App\Models\Outlet;
use App\Models\User;
use App\Models\UserRole;

/**
 * make:filament-user tidak bisa membuat user di sistem ini (role NOT NULL tanpa
 * default + outlet_id FK NOT NULL ke outlets kosong). app:create-owner adalah
 * satu-satunya jalan instalasi yang bisa menghasilkan user login — jadi ia diuji
 * langsung agar instalasi baru tidak pernah macet di "tak bisa login".
 */
test('creates a default outlet and an owner on a fresh install', function () {
    expect(User::count())->toBe(0)
        ->and(Outlet::count())->toBe(0);

    $this->artisan('app:create-owner', [
        '--name' => 'Owner',
        '--email' => 'owner@example.test',
        '--password' => 'rahasia123',
    ])->assertSuccessful();

    $owner = User::sole();

    expect(Outlet::count())->toBe(1)
        ->and($owner->role)->toBe(UserRole::Owner)
        ->and($owner->email)->toBe('owner@example.test')
        ->and($owner->outlet_id)->toBe(Outlet::sole()->id)
        ->and($owner->is_active)->toBeTrue()
        ->and(Hash::check('rahasia123', $owner->password))->toBeTrue();
});

test('reuses the existing outlet instead of creating a second', function () {
    $outlet = Outlet::factory()->create();

    $this->artisan('app:create-owner', [
        '--name' => 'Owner',
        '--email' => 'owner@example.test',
        '--password' => 'rahasia123',
    ])->assertSuccessful();

    expect(Outlet::count())->toBe(1)
        ->and(User::sole()->outlet_id)->toBe($outlet->id);
});

test('rejects a duplicate email without creating a user', function () {
    User::factory()->create(['email' => 'dup@example.test']);

    $this->artisan('app:create-owner', [
        '--name' => 'Owner',
        '--email' => 'dup@example.test',
        '--password' => 'rahasia123',
    ])->assertFailed();

    expect(User::where('email', 'dup@example.test')->count())->toBe(1);
});

test('rejects a too-short password', function () {
    $this->artisan('app:create-owner', [
        '--name' => 'Owner',
        '--email' => 'owner@example.test',
        '--password' => 'short',
    ])->assertFailed();

    expect(User::where('email', 'owner@example.test')->exists())->toBeFalse();
});
