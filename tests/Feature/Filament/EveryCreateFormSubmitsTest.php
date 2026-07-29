<?php

use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Livewire;

/**
 * Setiap form "Tambah" benar-benar DISIMPAN, bukan sekadar dibuka.
 *
 * Test asap yang ada membuktikan halamannya terbuka. Itu tidak menjamin
 * formnya bisa menyimpan: field wajib yang tidak ada di skema, relasi yang
 * berganti nama, atau enum yang berpindah nilai semuanya lolos saat halaman
 * dirender dan baru meledak saat tombol Simpan ditekan — yaitu setelah owner
 * mengetik seluruh isinya.
 *
 * Datanya diambil dari factory model masing-masing, jadi resource yang
 * ditambahkan bulan depan ikut teruji tanpa menyentuh berkas ini. Resource
 * yang factory-nya tidak bisa dipetakan ke formnya DICATAT, bukan dilewati
 * diam-diam — daftar itu ikut muncul saat test gagal.
 */
beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
});

/**
 * Nilai yang TIDAK bisa datang dari factory, per model.
 *
 * attributesToArray() hanya mengembalikan kolom tabel: password disembunyikan
 * cast 'hashed', dan roles adalah relasi many-to-many yang bukan kolom sama
 * sekali. Keduanya wajib di form, dan itu BENAR — akun panel tanpa kata sandi
 * atau tanpa peran adalah akun yang tidak bisa dipakai sekaligus tidak bisa
 * dibatasi. Yang kurang di sini test-nya, bukan formnya.
 *
 * @return array<class-string, callable(): array<string, mixed>>
 */
function kioskCreateFormSupplements(): array
{
    return [
        User::class => fn (): array => [
            'password' => 'kata-sandi-uji-123',
            'roles' => Role::query()->where('name', 'staf_operasional')->pluck('id')->all(),
        ],
    ];
}

test('every create form saves a record instead of blowing up', function () {
    $supplements = kioskCreateFormSupplements();
    $failures = [];
    $skipped = [];
    $checked = 0;

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        foreach ($resource::getPages() as $registration) {
            $page = $registration->getPage();

            if (! is_subclass_of($page, CreateRecord::class)) {
                continue;
            }

            $model = $resource::getModel();
            $name = class_basename($model);

            $attributes = rescue(
                fn (): array => $model::factory()->make()->attributesToArray(),
                null,
                false,
            );

            if ($attributes === null) {
                $skipped[] = $name.' (tanpa factory)';

                continue;
            }

            $attributes = array_merge(
                $attributes,
                isset($supplements[$model]) ? $supplements[$model]() : [],
            );

            try {
                Livewire::test($page)
                    ->fillForm($attributes)
                    ->call('create')
                    ->assertHasNoFormErrors();

                $checked++;
            } catch (Throwable $exception) {
                $failures[] = $name.': '.str($exception->getMessage())->limit(160)->toString();
            }
        }
    }

    expect($failures)->toBe([], "Form yang gagal menyimpan:\n- ".implode("\n- ", $failures)
        ."\n\nDilewati: ".(implode(', ', $skipped) ?: 'tidak ada'));

    // Penjaga terhadap kegagalan diam.
    expect($checked)->toBeGreaterThan(3, 'Tidak ada form yang benar-benar diuji.');
});
