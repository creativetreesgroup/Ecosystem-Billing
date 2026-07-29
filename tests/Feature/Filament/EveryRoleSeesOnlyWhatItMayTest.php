<?php

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Spatie\Permission\Models\Role;

/**
 * Matriks penuh: setiap peran mencoba membuka setiap daftar resource.
 *
 * Yang dibandingkan adalah IZIN YANG DIMILIKI peran terhadap PERILAKU HTTP
 * sesungguhnya — bukan kode terhadap dirinya sendiri. Perbedaan di antara
 * keduanya justru kelas bug yang paling berbahaya di panel ini:
 *
 *  - Resource tanpa policy. Filament mengizinkan secara bawaan bila policy-nya
 *    absen, jadi halaman keuangan terbuka untuk semua staf tanpa satu pun
 *    pesan salah. Tidak ada yang tahu sampai ada yang iseng mengetik URL-nya.
 *  - Policy yang mengabaikan izin. Ia ada, tetapi selalu menjawab true.
 *  - Peran yang diberi izin lebih dari yang dimaksud saat menyusun departemen.
 *
 * super_admin sengaja ikut diuji: ia memang menembus semuanya lewat Gate,
 * dan test ini memastikan jalan pintas itu memang hanya miliknya.
 */
test('no role can open a resource it has no permission for', function () {
    $mismatches = [];
    $checked = 0;

    $listPages = collect(Filament::getPanel('admin')->getResources())
        ->flatMap(function (string $resource): array {
            $model = $resource::getModel();

            foreach ($resource::getPages() as $registration) {
                $page = $registration->getPage();

                if (is_subclass_of($page, ListRecords::class)) {
                    return [[class_basename($model), $page::getUrl()]];
                }
            }

            return [];
        });

    expect($listPages)->not->toBeEmpty('Tidak ada daftar resource yang ditemukan.');

    foreach (Role::query()->pluck('name') as $roleName) {
        $user = User::factory()->create();
        $user->syncRoles([$roleName]);
        $user = $user->fresh();

        foreach ($listPages as [$modelName, $url]) {
            $permission = "ViewAny:{$modelName}";
            $allowed = $user->can($permission);

            $status = $this->actingAs($user)->get($url)->getStatusCode();
            $opened = $status === 200;

            if ($opened !== $allowed) {
                $mismatches[] = sprintf(
                    '%s pada %s: izin %s = %s, tetapi halaman menjawab %d',
                    $roleName,
                    $modelName,
                    $permission,
                    $allowed ? 'ADA' : 'TIDAK ADA',
                    $status,
                );
            }

            $checked++;
        }
    }

    expect($mismatches)->toBe([], "Otorisasi tidak sesuai izin:\n- ".implode("\n- ", $mismatches));

    // Penjaga terhadap kegagalan diam.
    expect($checked)->toBeGreaterThan(100, 'Matriks peran x resource nyaris kosong.');
});
