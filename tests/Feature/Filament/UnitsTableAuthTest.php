<?php

use App\Filament\Resources\Units\Pages\ListUnits;
use App\Filament\Widgets\OutletOverviewWidget;
use App\Filament\Widgets\UnitGridWidget;
use App\Models\User;
use Livewire\Livewire;

/**
 * Mengaktifkan/menonaktifkan unit adalah wewenang owner. Aksi bulk kustom tidak
 * tergerbang policy otomatis, jadi tanpa gerbang eksplisit seorang kasir bisa
 * menonaktifkan seluruh unit sekaligus dan mematikan operasional outlet.
 */
test('a cashier cannot see the activate/deactivate bulk actions', function () {
    // Kasir lantai boleh MELIHAT unit (departemen Maintenance), tapi tidak
    // mengaktifkan/menonaktifkannya — itu tingkat admin.
    $kasir = User::factory()->create();
    $kasir->syncRoles(['staf_operasional', 'staf_maintenance']);

    Livewire::actingAs($kasir)
        ->test(ListUnits::class)
        ->assertTableBulkActionHidden('deactivate')
        ->assertTableBulkActionHidden('activate');
});

test('an owner can see the activate/deactivate bulk actions', function () {
    Livewire::actingAs(User::factory()->owner()->create())
        ->test(ListUnits::class)
        ->assertTableBulkActionVisible('deactivate')
        ->assertTableBulkActionVisible('activate');
});

/**
 * KRITIS (uang): grid dasbor bukan ringkasan — ia bisa MEMULAI, MEMBERHENTIKAN
 * dan MENAGIH sesi. Ia sempat terbuka untuk siapa pun yang bisa masuk panel,
 * sehingga staf Keuangan yang ditolak dari /admin/rental-sessions tetap bisa
 * menutup sesi dan membukukan pembayaran tunai untuk shift yang tak pernah ia
 * jalani.
 */
test('a finance-only staffer cannot even see the unit grid', function () {
    $finance = User::factory()->create();
    $finance->syncRoles(['staf_keuangan']);

    $this->actingAs($finance);

    expect(UnitGridWidget::canView())->toBeFalse()
        ->and(OutletOverviewWidget::canView())->toBeFalse();
});

test('an operations staffer keeps the grid they work from', function () {
    $kasir = User::factory()->create();
    $kasir->syncRoles(['staf_operasional']);

    $this->actingAs($kasir);

    expect(UnitGridWidget::canView())->toBeTrue();
});
