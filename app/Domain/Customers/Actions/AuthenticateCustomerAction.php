<?php

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\CustomerPhone;
use App\Domain\Customers\Exceptions\TooManyPinAttemptsException;
use App\Models\Customer;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Memeriksa nomor HP + PIN.
 *
 * PIN hanya enam angka: sejuta kemungkinan, yang habis ditebak dalam hitungan
 * menit oleh skrip mana pun kalau percobaannya tidak dibatasi. Karena akun ini
 * memegang saldo sungguhan, pembatas percobaan BUKAN tambahan — ia bagian dari
 * cara PIN pendek boleh dipakai sama sekali.
 *
 * Dibatasi per nomor, bukan per alamat IP: seluruh outlet berbagi satu WiFi,
 * jadi membatasi per IP akan mengunci semua pelanggan begitu satu orang salah
 * ketik beberapa kali.
 */
class AuthenticateCustomerAction
{
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 300;

    public function handle(string $phone, string $pin): Customer
    {
        $normalised = CustomerPhone::normalise($phone);

        if ($normalised === null) {
            throw ValidationException::withMessages(['phone' => 'Nomor HP tidak dikenali.']);
        }

        $key = 'kiosk-pin:'.$normalised;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new TooManyPinAttemptsException(RateLimiter::availableIn($key));
        }

        $customer = Customer::query()->where('phone', $normalised)->first();

        // Pesan yang SAMA untuk nomor tidak terdaftar dan PIN salah. Pesan
        // berbeda akan memberi tahu penebak nomor mana yang punya akun —
        // separuh pekerjaannya selesai sebelum ia menebak satu PIN pun.
        if (! $customer?->verifyPin($pin) || ! $customer->is_active) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            throw ValidationException::withMessages(['pin' => 'Nomor HP atau PIN salah.']);
        }

        RateLimiter::clear($key);

        $customer->update(['last_seen_at' => now()]);

        return $customer;
    }
}
