<?php

namespace App\Domain\Discounts;

use App\Domain\Billing\Rupiah;
use App\Domain\Discounts\Exceptions\DiscountNotApplicableException;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use Illuminate\Support\Str;

/**
 * Satu-satunya tempat sebuah diskon dinilai & dipakai.
 *
 * Dua jalur, sengaja dipisah:
 * - preview(): read-only, untuk menampilkan potongan di layar SEBELUM bayar.
 * - redeem(): DIPANGGIL DI DALAM transaksi pemotongan; mengunci baris diskon,
 *   memvalidasi ULANG (termasuk kuota di bawah kunci), lalu mencatat pemakaian.
 *
 * Kuota dicek DI DALAM kunci baris diskon, meniru pola Wallet: tanpa itu dua
 * penebusan bersamaan sama-sama membaca "kuota masih ada" lalu keduanya lolos,
 * dan voucher terpakai melebihi batasnya.
 */
class DiscountEngine
{
    /**
     * Hitung potongan tanpa mencatat apa pun. Melempar bila voucher tak berlaku
     * (pesan sudah ramah-pelanggan).
     */
    public function preview(string $code, DiscountTarget $target, int $baseAmount, Customer $customer): DiscountResult
    {
        $discount = $this->findVoucher($code);

        return $this->evaluate($discount, $target, $baseAmount, $customer);
    }

    /**
     * Tebus voucher: kunci barisnya, validasi ulang, catat pemakaian, kembalikan
     * potongannya. WAJIB dipanggil di dalam transaksi pemotongan supaya kunci &
     * pencatatan atomik dengan pembayarannya.
     *
     * @param  array{customer_id?: int, rental_session_id?: int, payment_id?: int}  $link
     */
    public function redeem(string $code, DiscountTarget $target, int $baseAmount, Customer $customer, array $link): DiscountRedemption
    {
        $discount = Discount::query()
            ->where('code', Str::upper(trim($code)))
            ->where('source', DiscountSource::Voucher)
            ->lockForUpdate()
            ->first();

        if ($discount === null) {
            throw new DiscountNotApplicableException('Kode voucher tidak ditemukan.');
        }

        $result = $this->evaluate($discount, $target, $baseAmount, $customer);

        return DiscountRedemption::create([
            'discount_id' => $discount->id,
            'customer_id' => $customer->id,
            'amount' => $result->discount,
            ...$link,
        ]);
    }

    private function findVoucher(string $code): Discount
    {
        $discount = Discount::query()
            ->where('code', Str::upper(trim($code)))
            ->where('source', DiscountSource::Voucher)
            ->first();

        if ($discount === null) {
            throw new DiscountNotApplicableException('Kode voucher tidak ditemukan.');
        }

        return $discount;
    }

    /**
     * Semua aturan berlaku-tidaknya sebuah diskon, di satu tempat. Melempar pada
     * pelanggaran pertama; kalau lolos, mengembalikan potongan terhitung.
     */
    private function evaluate(Discount $discount, DiscountTarget $target, int $baseAmount, Customer $customer): DiscountResult
    {
        if (! $discount->is_active) {
            throw new DiscountNotApplicableException('Voucher ini sedang tidak aktif.');
        }

        if (! $discount->appliesTo($target)) {
            throw new DiscountNotApplicableException('Voucher ini tidak berlaku untuk '.$target->getLabel().'.');
        }

        $now = now();

        if ($discount->starts_at !== null && $now->lt($discount->starts_at)) {
            throw new DiscountNotApplicableException('Voucher ini belum berlaku.');
        }

        if ($discount->ends_at !== null && $now->gt($discount->ends_at)) {
            throw new DiscountNotApplicableException('Voucher ini sudah kedaluwarsa.');
        }

        if ($baseAmount < $discount->min_amount) {
            throw new DiscountNotApplicableException('Voucher ini berlaku mulai '.Rupiah::format($discount->min_amount).'.');
        }

        if ($discount->max_uses !== null && $discount->redemptions()->count() >= $discount->max_uses) {
            throw new DiscountNotApplicableException('Kuota voucher ini sudah habis.');
        }

        if ($discount->max_uses_per_customer !== null
            && $discount->redemptions()->where('customer_id', $customer->id)->count() >= $discount->max_uses_per_customer) {
            throw new DiscountNotApplicableException('Kamu sudah memakai voucher ini.');
        }

        $amount = $discount->type->discountOn($baseAmount, $discount->value);

        return new DiscountResult(
            discountId: $discount->id,
            label: $discount->name,
            discount: $amount,
            finalAmount: $baseAmount - $amount,
        );
    }
}
