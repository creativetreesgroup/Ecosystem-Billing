<?php

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Livewire\Livewire;

/**
 * Setiap tabel panel DICARI sungguhan, bukan sekadar dibuka.
 *
 * Membuka halaman hanya membuktikan querynya jalan apa adanya. Kolom
 * searchable() yang menunjuk relasi salah, atau kolom yang nilainya dihitung di
 * PHP lalu ditandai searchable, baru meledak saat kotak pencarian diketik —
 * yaitu di tangan kasir yang sedang mencari pelanggan, bukan di layar
 * pengembang yang hanya membuka daftarnya.
 *
 * Pola yang sama pernah terjadi pada aksi "Periksa bukti": halamannya terbuka
 * normal, aksinya yang meledak saat dibuka untuk pembayaran isi saldo.
 */
beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
});

test('searching every panel table returns instead of exploding', function () {
    $checked = 0;

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        foreach ($resource::getPages() as $registration) {
            $page = $registration->getPage();

            if (! is_subclass_of($page, ListRecords::class)) {
                continue;
            }

            // Kata yang pasti tidak cocok: yang diuji kemampuan MENJALANKAN
            // pencariannya, bukan hasilnya. Hasil kosong tetap sah.
            Livewire::test($page)
                ->searchTable('zzqx-tidak-ada')
                ->assertOk();

            $checked++;
        }
    }

    // Penjaga terhadap kegagalan diam: daftar kosong harus MERAH, bukan hijau
    // tanpa memeriksa apa pun.
    expect($checked)->toBeGreaterThan(10, 'Tidak ada tabel yang diperiksa.');
});

/**
 * Setiap aksi baris DIBUKA sungguhan untuk satu record nyata.
 *
 * Halaman yang terbuka normal tidak menjamin aksinya ikut sehat. "Periksa
 * bukti" dulu meledak hanya untuk pembayaran isi saldo, karena judulnya
 * mengakses ->rentalSession->unit yang null di jalur itu — daftarnya tetap
 * tampil rapi, layar merahnya baru muncul setelah kasir menekan tombolnya,
 * di depan pelanggan yang sedang menunggu.
 */
test('every record action opens for a real record', function () {
    $checked = 0;
    $skipped = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $model = $resource::getModel();
        $record = rescue(fn () => $model::query()->first() ?? $model::factory()->create(), null, false);

        if (! $record) {
            // Model tanpa factory dan tanpa data dicatat, bukan dilewati diam-diam.
            $skipped[] = class_basename($model);

            continue;
        }

        foreach ($resource::getPages() as $registration) {
            $page = $registration->getPage();

            if (! is_subclass_of($page, ListRecords::class)) {
                continue;
            }

            $component = Livewire::test($page);
            $table = $component->instance()->getTable();

            foreach ($table->getRecordActions() as $action) {
                // ActionGroup membungkus beberapa aksi; yang diuji isinya.
                $actions = method_exists($action, 'getActions') ? $action->getActions() : [$action];

                foreach ($actions as $single) {
                    $name = $single->getName();

                    // Aksi yang memang tidak diizinkan untuk record ini bukan
                    // kegagalan — ia justru sedang bekerja sebagaimana mestinya.
                    if (! rescue(fn () => $single->isVisible(), false, false)) {
                        continue;
                    }

                    Livewire::test($page)
                        ->mountAction(TestAction::make($name)->table($record))
                        ->assertHasNoActionErrors();

                    $checked++;
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(5, 'Tidak ada aksi baris yang diperiksa. Dilewati: '.implode(', ', $skipped));
});
