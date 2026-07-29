<?php

use App\Models\MenuOrder;
use App\Models\Role;
use App\Models\User;

/**
 * KRITIS (pengambilalihan sistem): tanpa RolePolicy, Filament menganggap
 * "tidak ada policy" sebagai "boleh". Layar Peran & Izin — satu-satunya layar
 * yang mengatur semua layar lain — berdiri tanpa penjaga, dan seorang teknisi
 * bisa membuka perannya sendiri, mencentang semua izin, lalu menyimpannya.
 */
test('a maintenance staffer cannot reach the roles screen at all', function () {
    $teknisi = User::factory()->create();
    $teknisi->syncRoles(['staf_maintenance']);

    expect($teknisi->can('viewAny', Role::class))->toBeFalse()
        ->and($teknisi->can('create', Role::class))->toBeFalse()
        ->and($teknisi->can('update', Role::query()->first()))->toBeFalse();
});

test('the system owner still manages roles', function () {
    $owner = User::factory()->owner()->create();

    expect($owner->can('viewAny', Role::class))->toBeTrue()
        ->and($owner->can('create', Role::class))->toBeTrue();
});

/** Peran super admin tidak bisa dihapus oleh siapa pun — termasuk dirinya. */
test('nobody can delete the super admin role', function () {
    $owner = User::factory()->owner()->create();
    $superAdmin = Role::query()->where('name', 'super_admin')->sole();

    expect($owner->can('delete', $superAdmin))->toBeFalse();
});

/**
 * KRITIS (uang): MenuOrderPolicy mengembalikan is_active saja, sehingga izin
 * departemen yang disusun rapi jadi mati total. Teknisi bisa membatalkan
 * pesanan dan MENGEMBALIKAN saldo pelanggan sementara makanannya sudah dimasak.
 */
test('a maintenance staffer cannot touch food orders', function () {
    $teknisi = User::factory()->create();
    $teknisi->syncRoles(['staf_maintenance']);

    expect($teknisi->can('viewAny', MenuOrder::class))->toBeFalse();
});

test('an operations staffer still works the kitchen queue', function () {
    $kasir = User::factory()->create();
    $kasir->syncRoles(['staf_operasional']);

    expect($kasir->can('viewAny', MenuOrder::class))->toBeTrue();
});
