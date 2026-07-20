<?php

namespace App\Domain\Billing;

use App\Domain\Devices\IntegrationKey;
use App\Models\Integration;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * QRIS lewat Midtrans, tanpa dependensi baru dan tanpa webhook.
 *
 * API-nya JSON biasa, jadi HTTP client Laravel sudah cukup — SDK resmi hanya
 * akan menambah dependensi untuk dua permintaan HTTP. Statusnya DI-POLL keluar,
 * bukan menunggu panggilan masuk: §14 melarang port forwarding masuk ke mesin
 * outlet, bukan koneksi keluar. Arsitektur LAN-only tetap utuh.
 */
class MidtransGateway
{
    private const SANDBOX_URL = 'https://api.sandbox.midtrans.com';

    private const PRODUCTION_URL = 'https://api.midtrans.com';

    public function integration(): ?Integration
    {
        return Integration::for(IntegrationKey::Midtrans);
    }

    public function isConfigured(): bool
    {
        $integration = $this->integration();

        return $integration?->is_active === true && filled($integration->token);
    }

    public function baseUrl(): string
    {
        $integration = $this->integration();

        if (filled($integration?->base_url)) {
            return rtrim($integration->base_url, '/');
        }

        return $integration?->option('is_production') ? self::PRODUCTION_URL : self::SANDBOX_URL;
    }

    /**
     * Membuat transaksi QRIS dan mengembalikan URL gambar QR-nya.
     *
     * order_id memakai id pembayaran, bukan id sesi: satu sesi bisa punya
     * beberapa upaya bayar (QRIS kedaluwarsa lalu dicoba lagi), dan Midtrans
     * menolak order_id yang sudah pernah dipakai.
     *
     * @return array{reference: string, qr_url: string}|null
     */
    public function createQris(Payment $payment): ?array
    {
        if (! $this->isConfigured()) {
            Log::warning('Midtrans belum dikonfigurasi, QRIS tidak dibuat.', ['payment_id' => $payment->id]);

            return null;
        }

        $orderId = 'CTB-'.$payment->id.'-'.now()->format('YmdHis');

        try {
            $response = $this->request()->post($this->baseUrl().'/v2/charge', [
                'payment_type' => 'qris',
                'transaction_details' => [
                    'order_id' => $orderId,
                    // Midtrans hanya menerima rupiah bulat. Nilai kita memang
                    // sudah integer rupiah, jadi tidak ada pembulatan diam-diam
                    // yang bisa membuat yang ditagih beda dari yang dibayar.
                    'gross_amount' => $payment->amount,
                ],
                'qris' => ['acquirer' => 'gopay'],
            ]);
        } catch (ConnectionException|Throwable $exception) {
            Log::warning('Midtrans tidak bisa dihubungi saat membuat QRIS.', [
                'payment_id' => $payment->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Midtrans menolak permintaan QRIS.', [
                'payment_id' => $payment->id,
                'status' => $response->status(),
                // Body TIDAK ikut dicatat: responsnya memuat kembali data
                // transaksi, dan log bukan tempatnya (§14).
            ]);

            return null;
        }

        $qrUrl = collect($response->json('actions', []))
            ->firstWhere('name', 'generate-qr-code')['url'] ?? null;

        if (blank($qrUrl)) {
            Log::warning('Midtrans menjawab tanpa URL QR.', ['payment_id' => $payment->id]);

            return null;
        }

        return ['reference' => $orderId, 'qr_url' => $qrUrl];
    }

    /**
     * Status sebuah transaksi menurut Midtrans, diterjemahkan ke status kita.
     */
    public function statusOf(Payment $payment): ?PaymentStatus
    {
        if (blank($payment->reference) || ! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->request()->get($this->baseUrl().'/v2/'.$payment->reference.'/status');
        } catch (ConnectionException|Throwable $exception) {
            Log::warning('Midtrans tidak bisa dihubungi saat cek status.', [
                'payment_id' => $payment->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        // PENJAGA UANG: nominal yang dilaporkan gateway HARUS sama dengan yang
        // kita tagih. Kalau berbeda, transaksinya tidak boleh diakui lunas
        // berapa pun statusnya — selisihnya berarti pelanggan membayar jumlah
        // lain, dan menandainya lunas membuat laporan mencatat uang yang tidak
        // pernah ada.
        $reported = (int) round((float) $response->json('gross_amount', 0));

        if ($reported !== $payment->amount) {
            Log::warning('Nominal Midtrans tidak cocok dengan tagihan.', [
                'payment_id' => $payment->id,
                'ditagih' => $payment->amount,
                'dilaporkan' => $reported,
            ]);

            return null;
        }

        return self::translateStatus(
            (string) $response->json('transaction_status'),
            (string) $response->json('fraud_status', 'accept'),
        );
    }

    /**
     * Peta status Midtrans ke status kita.
     *
     * "capture" HANYA lunas bila fraud_status-nya accept — capture dengan
     * fraud_status challenge berarti Midtrans sendiri belum yakin, dan
     * menganggapnya lunas berarti mengakui uang yang masih bisa ditarik.
     *
     * Status yang tidak dikenal sengaja mengembalikan null (biarkan menunggu),
     * BUKAN menganggapnya gagal: menutup transaksi yang sebenarnya berhasil
     * jauh lebih merugikan daripada memeriksanya sekali lagi nanti.
     */
    public static function translateStatus(string $transactionStatus, string $fraudStatus = 'accept'): ?PaymentStatus
    {
        return match ($transactionStatus) {
            'settlement' => PaymentStatus::Paid,
            'capture' => $fraudStatus === 'accept' ? PaymentStatus::Paid : PaymentStatus::Pending,
            'pending' => PaymentStatus::Pending,
            'expire' => PaymentStatus::Expired,
            'deny', 'cancel', 'failure' => PaymentStatus::Rejected,
            default => null,
        };
    }

    private function request(): PendingRequest
    {
        // Basic auth dengan server key sebagai username dan sandi KOSONG —
        // begitu bentuk otentikasi Midtrans.
        return Http::withBasicAuth((string) $this->integration()?->token, '')
            ->acceptJson()
            ->timeout(10)
            ->retry(2, 200, throw: false);
    }
}
