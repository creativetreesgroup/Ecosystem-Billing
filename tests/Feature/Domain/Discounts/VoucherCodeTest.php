<?php

use App\Domain\Discounts\VoucherCode;
use App\Models\Discount;

/**
 * Kode voucher acak, bukan turunan nama promonya.
 *
 * Kode yang berasal dari nama bisa ditebak — siapa pun yang melihat spanduk
 * promo bisa mencoba variasinya di kios sampai tembus, dan kuotanya habis oleh
 * orang yang tak pernah diberi voucher.
 */
test('it generates a readable, unguessable code', function () {
    $code = VoucherCode::generate();

    expect($code)->toMatch('/^[34679ACDEFGHJKMNPQRTUVWXY]{4}-[34679ACDEFGHJKMNPQRTUVWXY]{4}$/');
});

/** Huruf & angka yang tertukar saat dibacakan lewat telepon tidak dipakai. */
test('the alphabet leaves out the characters people confuse', function () {
    $codes = collect(range(1, 200))->map(fn (): string => VoucherCode::generate())->implode('');

    foreach (['0', 'O', '1', 'I', 'L', '5', 'S', '8', 'B', '2', 'Z'] as $ambiguous) {
        expect($codes)->not->toContain($ambiguous);
    }
});

test('it never hands out a code that already exists', function () {
    $taken = VoucherCode::generate();
    Discount::factory()->create(['code' => $taken, 'source' => 'voucher']);

    $codes = collect(range(1, 50))->map(fn (): string => VoucherCode::generate());

    expect($codes)->not->toContain($taken)
        ->and($codes->unique())->toHaveCount(50);
});

/**
 * REGRESI: form panel bukan satu-satunya pintu ke tabel ini — seeder, tinker,
 * dan impor juga menulis ke sini. Voucher tanpa kode tidak melempar galat apa
 * pun; ia hanya diam-diam mustahil dipakai, dan itu baru ketahuan saat
 * pelanggan berdiri di kios memegang voucher yang tidak berlaku.
 */
test('a voucher saved without a code gets one anyway', function () {
    $voucher = Discount::factory()->create(['source' => 'voucher', 'code' => null]);

    expect($voucher->fresh()->code)->not->toBeNull()
        ->and($voucher->fresh()->code)->toMatch('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/');
});

/** Promo otomatis TIDAK boleh diberi kode: kode kedua untuk promo yang sama
 *  berarti kuotanya bisa terpakai lewat dua jalur. */
test('an automatic promo is left without a code', function () {
    $promo = Discount::factory()->create(['source' => 'promo', 'code' => null]);

    expect($promo->fresh()->code)->toBeNull();
});
