<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\MidtransGateway;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Sessions\Exceptions\UnitAlreadyActiveException;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\Package;
use App\Models\Payment;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pelanggan memesan sendiri dari HP: membuat tagihan LEBIH DULU, sesinya belakangan.
 *
 * Urutan ini yang membuat kios aman. Di panel kasir, kasir memegang uangnya di
 * depan sehingga sesi boleh langsung berjalan. Di kios tidak ada penjaga: kalau
 * sesi dimulai duluan dan pembayarannya menyusul, siapa pun bisa main berjam-jam
 * lalu pergi — dan sistem tidak punya cara apa pun menagihnya.
 *
 * Jadi yang dibuat di sini HANYA tagihan. Sesinya baru lahir di
 * StartPaidKioskSessionAction, setelah pembayarannya benar-benar lunas.
 */
class OpenKioskCheckoutAction
{
    public function __construct(private readonly MidtransGateway $gateway) {}

    /**
     * @return array{payment: Payment, qr_url: ?string}
     */
    public function handle(Unit $unit, Package $package, PaymentMethod $method, ?string $customerName = null): array
    {
        if ($package->unit_type_id !== $unit->unit_type_id) {
            throw new InvalidArgumentException('Paket ini tidak berlaku untuk tipe unit tersebut.');
        }

        if (! $package->is_active || ! $unit->is_active) {
            throw new InvalidArgumentException('Unit atau paket sedang tidak tersedia.');
        }

        // Tunai tidak pernah lewat kios: tidak ada cara memastikan uang tunai
        // berpindah tanpa manusia yang menerimanya.
        if ($method === PaymentMethod::Cash) {
            throw new InvalidArgumentException('Pembayaran tunai diselesaikan di kasir, bukan di kios.');
        }

        $payment = DB::transaction(function () use ($unit, $package, $method, $customerName): Payment {
            $locked = Unit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();

            if ($locked->activeSession()->exists()) {
                throw new UnitAlreadyActiveException("Unit {$locked->code} sedang dipakai.");
            }

            // Tagihan yang belum dibayar untuk unit yang sama dibatalkan lebih
            // dulu. Tanpa ini, dua orang bisa memindai QR unit yang sama dan
            // keduanya membayar untuk satu unit — uang kedua tidak punya tempat
            // duduk, dan mengembalikannya jauh lebih mahal daripada mencegahnya.
            $menggantung = RentalSession::query()
                ->where('unit_id', $locked->id)
                ->where('status', SessionStatus::Pending)
                ->get();

            Payment::query()
                ->whereIn('rental_session_id', $menggantung->modelKeys())
                ->where('status', PaymentStatus::Pending)
                ->update(['status' => PaymentStatus::Expired]);

            // Sesinya ikut dibatalkan, bukan cuma tagihannya. Sesi menunggu
            // yang ditinggalkan akan menumpuk di riwayat sebagai baris yang
            // tidak pernah terjadi, dan mengaburkan berapa kali unit ini
            // benar-benar dipakai.
            RentalSession::query()
                ->whereIn('id', $menggantung->modelKeys())
                ->update(['status' => SessionStatus::Voided, 'void_reason' => 'Tagihan kios digantikan pesanan baru.']);

            $session = RentalSession::create([
                'unit_id' => $locked->id,
                'opened_by' => self::kioskOperator()->id,
                'package_id' => $package->id,
                'customer_name' => $customerName ?: null,
                'type' => SessionType::Package,
                // Sengaja BUKAN Active: sesi ini belum berjalan, ia baru
                // rencana yang menunggu dibayar. Status aktif akan diberikan
                // StartPaidKioskSessionAction begitu uangnya terbukti masuk.
                'status' => SessionStatus::Pending,
                'expiry_token' => (string) Str::uuid(),
                'base_amount' => $package->price,
                'extra_amount' => 0,
                'total_amount' => $package->price,
                'payment_method' => $method,
            ]);

            return $session->payments()->create([
                'method' => $method,
                'status' => PaymentStatus::Pending,
                'amount' => $package->price,
            ]);
        });

        if ($method !== PaymentMethod::Qris) {
            return ['payment' => $payment, 'qr_url' => null];
        }

        $created = $this->gateway->createQris($payment);

        if ($created === null) {
            // Gagal membuat QR bukan alasan meninggalkan tagihan menggantung
            // yang tidak pernah bisa dibayar.
            $payment->update(['status' => PaymentStatus::Expired]);

            throw new RuntimeException('QRIS sedang tidak bisa dibuat. Coba lagi, atau bayar lewat kasir.');
        }

        $payment->update(['reference' => $created['reference']]);

        return ['payment' => $payment->fresh(), 'qr_url' => $created['qr_url']];
    }

    /**
     * Sesi dari kios tetap butuh "siapa yang membuka". Dipakai akun owner
     * pertama sebagai penanggung jawab sistem — bukan membiarkan kolomnya
     * kosong, karena setiap sesi harus bisa ditelusuri ke seseorang.
     */
    private static function kioskOperator(): User
    {
        return User::query()->where('role', UserRole::Owner)->orderBy('id')->firstOrFail();
    }
}
