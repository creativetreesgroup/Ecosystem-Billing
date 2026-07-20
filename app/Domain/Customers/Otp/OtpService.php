<?php

namespace App\Domain\Customers\Otp;

use App\Domain\Customers\CustomerPhone;
use App\Domain\Customers\Exceptions\TooManyPinAttemptsException;
use App\Models\OtpCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Membuat, mengirim, dan memeriksa kode sekali pakai.
 *
 * Seluruh aturan keamanannya tinggal di sini, terpisah dari penyedia
 * pengirimannya — mengganti gateway WhatsApp tidak boleh bisa melonggarkan
 * satu pun aturan di bawah ini.
 */
class OtpService
{
    /** Cukup lama untuk membuka WhatsApp, cukup pendek untuk tidak berguna bila bocor. */
    private const VALID_SECONDS = 300;

    /** Salah ketik wajar; ini bukan tempat menebak. */
    private const MAX_VERIFY_ATTEMPTS = 5;

    /** Tiap permintaan MENGIRIM PESAN BERBAYAR — dan ke HP orang sungguhan. */
    private const MAX_REQUESTS = 3;

    private const REQUEST_WINDOW_SECONDS = 900;

    public function __construct(private readonly OtpChannel $channel) {}

    /**
     * @return array{sent: bool, expires_in: int}
     */
    public function request(string $rawPhone): array
    {
        $phone = $this->normalise($rawPhone);
        $key = 'otp-request:'.$phone;

        // Pembatas ini melindungi DUA hal sekaligus: biaya pesan, dan orang
        // yang nomornya dipakai iseng — tanpa batas, siapa pun bisa membuat HP
        // orang lain berdering terus-menerus dengan mengetik nomornya.
        if (RateLimiter::tooManyAttempts($key, self::MAX_REQUESTS)) {
            throw new TooManyPinAttemptsException(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::REQUEST_WINDOW_SECONDS);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($phone, $code): void {
            // Kode lama untuk nomor yang sama dihanguskan. Membiarkan beberapa
            // kode hidup sekaligus memperbesar peluang tebakan berkali lipat,
            // dan membuat "kode mana yang benar" jadi pertanyaan tanpa jawaban.
            OtpCode::query()->where('phone', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);

            OtpCode::create([
                'phone' => $phone,
                'code_hash' => $code,
                'expires_at' => now()->addSeconds(self::VALID_SECONDS),
                'sent_via' => $this->channel->name(),
            ]);
        });

        return [
            'sent' => $this->channel->send($phone, $code),
            'expires_in' => self::VALID_SECONDS,
        ];
    }

    /**
     * Mengembalikan nomor yang sudah diseragamkan bila kodenya benar.
     */
    public function verify(string $rawPhone, string $code): string
    {
        $phone = $this->normalise($rawPhone);

        // Transaksi TIDAK boleh membungkus pelemparan exception-nya.
        //
        // Versi pertama melempar ValidationException dari dalam
        // DB::transaction(), sehingga penambahan `attempts` dan penghangusan
        // kode ikut ter-ROLLBACK — pembatas tebakan tidak pernah bekerja sama
        // sekali, dan kode enam angka bisa ditebak tanpa batas. Ditemukan oleh
        // test, bukan oleh pembacaan ulang.
        //
        // Jadi transaksinya hanya MEMUTUSKAN dan menyimpan; keputusannya
        // dilempar setelah perubahan itu benar-benar tersimpan.
        $hasil = DB::transaction(function () use ($phone, $code): string {
            $otp = OtpCode::query()
                ->where('phone', $phone)
                ->whereNull('consumed_at')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $otp || ! $otp->isUsable()) {
                return 'kedaluwarsa';
            }

            if ($otp->attempts >= self::MAX_VERIFY_ATTEMPTS) {
                $otp->update(['consumed_at' => now()]);

                return 'terlalu-banyak';
            }

            $otp->increment('attempts');

            if (! password_verify($code, $otp->getRawOriginal('code_hash'))) {
                return 'salah';
            }

            // Dihanguskan SEBELUM dipakai, di dalam kunci baris: kode sekali
            // pakai yang bisa dipakai dua kali bukan kode sekali pakai.
            $otp->update(['consumed_at' => now()]);

            return 'benar';
        });

        if ($hasil === 'benar') {
            RateLimiter::clear('otp-request:'.$phone);

            return $phone;
        }

        throw ValidationException::withMessages(['code' => match ($hasil) {
            'kedaluwarsa' => 'Kode sudah tidak berlaku. Minta kode baru.',
            'terlalu-banyak' => 'Terlalu banyak percobaan. Minta kode baru.',
            default => 'Kode salah.',
        }]);
    }

    private function normalise(string $rawPhone): string
    {
        $phone = CustomerPhone::normalise($rawPhone);

        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'Nomor WhatsApp tidak dikenali. Contoh: 081234567890']);
        }

        return $phone;
    }
}
