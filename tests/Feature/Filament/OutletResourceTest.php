<?php

use App\Filament\Resources\Outlets\Pages\CreateOutlet;
use App\Filament\Resources\UnitTypes\Pages\CreateUnitType;
use App\Models\Outlet;
use App\Models\UnitType;
use App\Models\User;
use Livewire\Livewire;

/**
 * Inti dari resource ini: outlet bisa DITAMBAH dari panel. Sebelumnya hanya ada
 * satu outlet dari seeder dan dropdown "Outlet" di form lain tak pernah bertambah
 * isinya. Timezone default terisi, is_active menyala tanpa perlu disentuh.
 */
test('an owner can add a new outlet from the panel', function () {
    $owner = User::factory()->owner()->create();

    Livewire::actingAs($owner)->test(CreateOutlet::class)
        ->fillForm([
            'name' => 'Cabang Bekasi',
            'timezone' => 'Asia/Jakarta',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $outlet = Outlet::where('name', 'Cabang Bekasi')->sole();
    expect($outlet->timezone)->toBe('Asia/Jakarta')
        ->and($outlet->is_active)->toBeTrue();
});

/**
 * Alasan resource ini dibuat: outlet baru langsung bisa dipilih saat membuat
 * Tipe Unit — dropdown-nya membaca tabel outlets, bukan daftar mati.
 */
test('a newly added outlet can be selected when creating a unit type', function () {
    $owner = User::factory()->owner()->create();
    $outlet = Outlet::factory()->create(['name' => 'Cabang Depok']);

    Livewire::actingAs($owner)->test(CreateUnitType::class)
        ->fillForm([
            'outlet_id' => $outlet->id,
            'name' => 'VIP',
            'hourly_rate' => 20_000,
            'sort_order' => 1,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(UnitType::where('name', 'VIP')->sole()->outlet_id)->toBe($outlet->id);
});

/**
 * Outlet adalah Data Master — sama seperti Tipe Unit & Paket, hanya owner yang
 * boleh. Kasir yang menebak URL-nya harus kena 403 (lih. PanelAccessTest).
 */
test('a kasir cannot access outlets', function () {
    $kasir = User::factory()->create();

    $this->actingAs($kasir)->get('/admin/outlets')->assertForbidden();
});
