<?php

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ViewRecord;

/**
 * Setiap halaman panel dibuka sungguhan, satu per satu.
 *
 * "Tidak ada bug di bagian mana pun" bukan sesuatu yang bisa diklaim — hanya
 * bisa dijalankan. Halaman Filament gampang pecah tanpa ada yang tahu: satu
 * kolom menunjuk relasi yang berganti nama, satu closure warna menerima enum
 * alih-alih string, satu form memakai field yang sudah dihapus. Semuanya lolos
 * dari analisis statis dan baru muncul sebagai layar merah di depan kasir.
 *
 * Yang dijaga di sini bukan tampilannya, melainkan yang paling dasar: halaman
 * itu MEMBUKA. Dan karena daftar halamannya diambil dari panel, resource yang
 * ditambahkan bulan depan otomatis ikut teruji tanpa menyentuh berkas ini.
 */
beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
});

/**
 * Daftar halamannya diambil DI DALAM test, bukan lewat dataset: dataset
 * dievaluasi saat Pest mengumpulkan test, ketika panel Filament belum ter-boot,
 * sehingga daftarnya kosong dan seluruh test lulus tanpa membuka satu halaman
 * pun. Versi pertama berkas ini melakukan persis itu.
 */
test('every resource page opens without blowing up', function () {
    $checked = 0;

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $model = $resource::getModel();

        foreach ($resource::getPages() as $registration) {
            $page = $registration->getPage();
            $needsRecord = is_subclass_of($page, EditRecord::class) || is_subclass_of($page, ViewRecord::class);

            $record = $needsRecord
                ? rescue(fn () => $model::query()->first() ?? $model::factory()->create(), null, false)
                : null;

            // Model tanpa factory dan tanpa data tidak bisa dibukakan halaman
            // Edit-nya di sini; dilewati, bukan dianggap lulus diam-diam.
            if ($needsRecord && ! $record) {
                continue;
            }

            $url = $record ? $page::getUrl(['record' => $record->getKey()]) : $page::getUrl();

            $this->get($url)->assertSuccessful("Halaman {$page} gagal dibuka ({$url})");
            $checked++;
        }
    }

    // Penjaga terhadap kegagalan diam: kalau daftarnya kosong lagi, test ini
    // harus MERAH, bukan hijau tanpa memeriksa apa pun.
    expect($checked)->toBeGreaterThan(20, 'Daftar halaman panel kosong — test ini tidak memeriksa apa pun.');
});

test('every standalone page opens without blowing up', function () {
    foreach (Filament::getPanel('admin')->getPages() as $page) {
        if (! $page::canAccess()) {
            continue;
        }

        $this->get($page::getUrl())->assertSuccessful();
    }
});

/**
 * Halaman yang dibuka SEBAGAI KASIR, bukan owner: jalur inilah yang dipakai
 * setiap hari, dan justru yang paling jarang dicoba saat mengembangkan fitur
 * (pengembang selalu login sebagai owner).
 */
test('the pages a cashier actually uses open for a cashier', function (string $path) {
    $kasir = User::factory()->create();
    $kasir->syncRoles(['staf_operasional', 'staf_maintenance', 'staf_keuangan']);

    $this->actingAs($kasir)->get($path)->assertSuccessful();
})->with([
    '/admin',
    '/admin/units',
    '/admin/device-alerts',
    '/admin/customers',
    '/admin/payments',
    '/admin/menu-orders',
]);
