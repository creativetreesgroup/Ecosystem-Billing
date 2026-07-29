<?php

use App\Domain\Users\PermissionGroup;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;

beforeEach(fn () => test()->seed(RolesAndPermissionsSeeder::class));

/**
 * Setiap izin harus punya SATU rumah.
 *
 * Grup adalah cara pemilik outlet membaca daftar izin — "Kasir mengurus
 * Operasional, Finance mengurus Keuangan". Izin yang tidak masuk grup mana pun
 * tidak akan pernah muncul di layar edit peran, jadi ia diam-diam mustahil
 * diberikan; izin yang masuk dua grup membuat "Admin Finance bisa semua di
 * Keuangan" berarti ia juga menyentuh modul lain tanpa ada yang menyadarinya.
 *
 * Test ini yang menahan keduanya, termasuk saat resource baru ditambahkan
 * nanti — dan itulah saat paling mungkin seseorang lupa memasukkannya.
 */
test('every generated permission belongs to exactly one group', function () {
    $grouped = collect(PermissionGroup::cases())
        ->flatMap(fn (PermissionGroup $group): array => $group->entities());

    $duplicates = $grouped->duplicates();

    expect($duplicates)->toBeEmpty("Entitas ini masuk lebih dari satu grup: {$duplicates->implode(', ')}");

    $known = $grouped->all();
    $orphans = Permission::query()->pluck('name')
        ->map(fn (string $name): string => PermissionGroup::entityOf($name))
        ->unique()
        ->reject(fn (string $entity): bool => in_array($entity, $known, true))
        ->values();

    expect($orphans)->toBeEmpty("Entitas ini belum masuk grup mana pun: {$orphans->implode(', ')}");
});

test('a group resolves back from any of its entities', function () {
    expect(PermissionGroup::for('Payment'))->toBe(PermissionGroup::Keuangan)
        ->and(PermissionGroup::for('RentalSession'))->toBe(PermissionGroup::Operasional)
        ->and(PermissionGroup::for('DeviceAlert'))->toBe(PermissionGroup::Maintenance)
        ->and(PermissionGroup::for('Setting'))->toBe(PermissionGroup::Sistem)
        ->and(PermissionGroup::for('EntitasKarangan'))->toBeNull();
});

/** Izin sebuah grup adalah semua izin milik entitas di dalamnya — tidak lebih. */
test('a group owns every permission of its entities and nothing else', function () {
    $keuangan = PermissionGroup::Keuangan->permissionNames();

    expect($keuangan)->toContain('ViewAny:Payment')
        ->and($keuangan)->toContain('View:SalesReport')
        ->and($keuangan)->not->toContain('ViewAny:Unit')
        ->and($keuangan)->not->toContain('ViewAny:User');
});

test('every group is labelled in Indonesian for the panel', function () {
    foreach (PermissionGroup::cases() as $group) {
        expect($group->getLabel())->not->toBe('')
            ->and($group->description())->not->toBe('');
    }
});
