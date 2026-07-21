<?php

use App\Filament\Resources\Units\Pages\ListUnits;
use App\Models\User;
use Livewire\Livewire;

/**
 * Mengaktifkan/menonaktifkan unit adalah wewenang owner. Aksi bulk kustom tidak
 * tergerbang policy otomatis, jadi tanpa gerbang eksplisit seorang kasir bisa
 * menonaktifkan seluruh unit sekaligus dan mematikan operasional outlet.
 */
test('a cashier cannot see the activate/deactivate bulk actions', function () {
    Livewire::actingAs(User::factory()->create()) // default role = kasir
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
