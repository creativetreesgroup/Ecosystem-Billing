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
    public function preview(string $code, DiscountTarget $target, int $baseAmount, ?Customer $customer = null): DiscountResult
    {
        $discount = $this->findVoucher($code);

        return $this->evaluate($discount, $target, $baseAmount, $customer);
    }

    /**
     * Tebus voucher: kunci barisnya, validasi ulang, catat pemakaian, kembalikan
     * potongannya. WAJIB dipanggil di dalam transaksi pemotongan supaya kunci &
     * pencatatan atomik dengan pembayarannya.
     *
     * @param  array{rental_session_id?: int, payment_id?: int}  $link
     */
    public function redeem(string $code, DiscountTarget $target, int $baseAmount, ?Customer $customer, array $link): DiscountRedemption
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
            'customer_id' => $customer?->id,
            'amount' => $result->discount,
            ...$link,
        ]);
    }

    /**
     * Voucher bila kodenya diberi, selain itu PROMO OTOMATIS terbaik untuk
     * transaksi ini — satu pintu yang dipakai semua titik pemotongan supaya
     * voucher & promo berlaku seragam. Mengembalikan redemption (potongannya di
     * ->amount) atau null bila tak ada diskon.
     *
     * Voucher tak valid MELEMPAR (pelanggan mengetik kode, berhak tahu sebabnya).
     * Promo yang gagal ditebus (mis. kuotanya habis persis di antara pemilihan &
     * penguncian) DILEWATI diam-diam — promo tak boleh menggagalkan pembelian.
     *
     * @param  array{rental_session_id?: int, payment_id?: int}  $link
     */
    public function apply(?string $voucherCode, DiscountTarget $target, int $baseAmount, ?Customer $customer, array $link): ?DiscountRedemption
    {
        if ($voucherCode !== null && trim($voucherCode) !== '') {
            return $this->redeem($voucherCode, $target, $baseAmount, $customer, $link);
        }

        $promo = $this->bestPromo($target, $baseAmount, $customer);

        if ($promo === null) {
            return null;
        }

        try {
            return $this->lockAndRecord($promo->id, $target, $baseAmount, $customer, $link);
        } catch (DiscountNotApplicableException) {
            return null;
        }
    }

    /**
     * Promo otomatis dengan potongan TERBESAR yang berlaku untuk transaksi ini,
     * atau null. Read-only — dipakai apply() maupun pratinjau UI.
     */
    public function bestPromo(DiscountTarget $target, int $baseAmount, ?Customer $customer): ?Discount
    {
        return Discount::query()
            ->where('source', DiscountSource::Promo)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Discount $promo) => $this->reasonUnapplicable($promo, $target, $baseAmount, $customer) === null)
            ->sortByDesc(fn (Discount $promo) => $promo->type->discountOn($baseAmount, $promo->value))
            ->first();
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
     * Kunci baris diskonnya, validasi ulang (kuota di bawah kunci), catat
     * pemakaian. Dipakai penebusan voucher maupun promo otomatis.
     *
     * @param  array{rental_session_id?: int, payment_id?: int}  $link
     */
    private function lockAndRecord(int $discountId, DiscountTarget $target, int $baseAmount, ?Customer $customer, array $link): DiscountRedemption
    {
        $discount = Discount::query()->whereKey($discountId)->lockForUpdate()->firstOrFail();

        $result = $this->evaluate($discount, $target, $baseAmount, $customer);

        return DiscountRedemption::create([
            'discount_id' => $discount->id,
            'customer_id' => $customer?->id,
            'amount' => $result->discount,
            ...$link,
        ]);
    }

    /**
     * Validasi + hitung. Melempar dengan pesan ramah bila tak berlaku.
     */
    private function evaluate(Discount $discount, DiscountTarget $target, int $baseAmount, ?Customer $customer): DiscountResult
    {
        $reason = $this->reasonUnapplicable($discount, $target, $baseAmount, $customer);

        if ($reason !== null) {
            throw new DiscountNotApplicableException($reason);
        }

        $amount = $discount->type->discountOn($baseAmount, $discount->value);

        return new DiscountResult(
            discountId: $discount->id,
            label: $discount->name,
            discount: $amount,
            finalAmount: $baseAmount - $amount,
        );
    }

    /**
     * Semua aturan berlaku-tidaknya di SATU tempat. Mengembalikan alasan penolakan
     * (pesan ramah) atau null bila lolos — dipakai evaluate() (melempar) maupun
     * bestPromo() (menyaring diam-diam).
     */
    private function reasonUnapplicable(Discount $discount, DiscountTarget $target, int $baseAmount, ?Customer $customer): ?string
    {
        if (! $discount->is_active) {
            return 'Voucher ini sedang tidak aktif.';
        }

        if (! $discount->appliesTo($target)) {
            return 'Voucher ini tidak berlaku untuk '.$target->getLabel().'.';
        }

        $now = now();

        if ($discount->starts_at !== null && $now->lt($discount->starts_at)) {
            return 'Voucher ini belum berlaku.';
        }

        if ($discount->ends_at !== null && $now->gt($discount->ends_at)) {
            return 'Voucher ini sudah kedaluwarsa.';
        }

        if ($baseAmount < $discount->min_amount) {
            return 'Voucher ini berlaku mulai '.Rupiah::format($discount->min_amount).'.';
        }

        if ($discount->max_uses !== null && $discount->redemptions()->count() >= $discount->max_uses) {
            return 'Kuota voucher ini sudah habis.';
        }

        // Kuota per-pelanggan hanya berlaku bila ada akun. Sesi kasir (nama bebas,
        // tanpa akun) tak bisa dilacak per orang — kuota totalnya tetap menjaga.
        if ($customer !== null
            && $discount->max_uses_per_customer !== null
            && $discount->redemptions()->where('customer_id', $customer->id)->count() >= $discount->max_uses_per_customer) {
            return 'Kamu sudah memakai voucher ini.';
        }

        return null;
    }
}
