<?php

use App\Models\User;

test('kasir gets a 403 on owner-only pages, even by direct URL', function (string $path) {
    $kasir = User::factory()->create();

    $this->actingAs($kasir)->get($path)->assertForbidden();
})->with([
    '/admin/settings',
    '/admin/rental-sessions',
    '/admin/unit-types',
    '/admin/packages',
    '/admin/sales-report',
    '/admin/users',
]);

test('owner can reach every admin page', function (string $path) {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get($path)->assertSuccessful();
})->with([
    '/admin',
    '/admin/settings',
    '/admin/rental-sessions',
    '/admin/unit-types',
    '/admin/packages',
    '/admin/units',
    '/admin/device-alerts',
    '/admin/sales-report',
    '/admin/users',
]);

test('kasir can reach the dashboard, units, and device alerts', function (string $path) {
    $kasir = User::factory()->create();

    $this->actingAs($kasir)->get($path)->assertSuccessful();
})->with([
    '/admin',
    '/admin/units',
    '/admin/device-alerts',
]);

test('an inactive user cannot access the panel at all', function () {
    $inactiveKasir = User::factory()->create(['is_active' => false]);

    $this->actingAs($inactiveKasir)->get('/admin')->assertForbidden();
});
