<?php

use App\Models\Package;
use App\Models\UnitType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Daftar paket aktif dibaca tiap poll layar kios, jadi disimpan di cache. Bukti
 * hit-nya: setelah dibaca sekali, menghapus barisnya LANGSUNG di DB (menembus
 * event model yang membuang cache) tidak mengubah hasilnya.
 */
test('activeForUnitType serves packages from cache without re-querying', function () {
    $type = UnitType::factory()->create();
    Package::factory()->for($type, 'unitType')->create(['is_active' => true]);

    expect(Package::activeForUnitType($type->id))->toHaveCount(1);

    DB::table('packages')->where('unit_type_id', $type->id)->delete();

    expect(Package::activeForUnitType($type->id))->toHaveCount(1); // masih dari cache
});

/**
 * Invalidasi tepat: menambah/menonaktifkan paket membuang cache tipe unitnya.
 */
test('changing a package busts its unit-type cache immediately', function () {
    $type = UnitType::factory()->create();
    $package = Package::factory()->for($type, 'unitType')->create(['is_active' => true]);

    expect(Package::activeForUnitType($type->id))->toHaveCount(1);

    $package->update(['is_active' => false]);
    expect(Package::activeForUnitType($type->id))->toHaveCount(0);

    Package::factory()->for($type, 'unitType')->create(['is_active' => true]);
    expect(Package::activeForUnitType($type->id))->toHaveCount(1);
});

/**
 * Bila paket dipindah ke tipe lain, cache tipe LAMA ikut dibuang — kalau tidak,
 * daftar tipe lama tetap memuat paket yang sudah tak ada di sana.
 */
test('moving a package to another unit type busts the old type cache', function () {
    $old = UnitType::factory()->create();
    $new = UnitType::factory()->create();
    $package = Package::factory()->for($old, 'unitType')->create(['is_active' => true]);

    expect(Package::activeForUnitType($old->id))->toHaveCount(1);

    $package->update(['unit_type_id' => $new->id]);

    expect(Package::activeForUnitType($old->id))->toHaveCount(0)
        ->and(Package::activeForUnitType($new->id))->toHaveCount(1);
});

/**
 * Yang DICACHE harus array mentah, bukan objek model. Store redis produksi
 * meng-unserialize dengan `allowed_classes: false` (config `cache.serializable_classes`,
 * default keamanan Laravel), jadi objek apa pun terbaca sebagai __PHP_Incomplete_Class
 * dan meledak di return type — inilah 500 di layar kios. Test suite pakai cache
 * `array` yang tak menserialisasi, jadi invariannya dijaga langsung di sini: isi
 * cache = array mentah, sementara kontrak keluaran tetap Collection<Package>.
 */
test('activeForUnitType caches raw arrays, not Eloquent objects', function () {
    $type = UnitType::factory()->create();
    Package::factory()->for($type, 'unitType')->create(['is_active' => true, 'price' => 15000]);

    $result = Package::activeForUnitType($type->id);

    $cached = Cache::get('packages:active:unit_type:'.$type->id);
    expect($cached)->toBeArray()
        ->and($cached[0])->toBeArray();

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->first())->toBeInstanceOf(Package::class)
        ->and($result->first()->price)->toBeInt()
        ->and($result->first()->is_active)->toBeTrue();
});
