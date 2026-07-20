<?php

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\CustomerPhone;
use App\Models\Customer;
use Illuminate\Validation\ValidationException;

/**
 * Membuat akun pelanggan dari kios.
 *
 * Sengaja seminimal mungkin: nama, nomor HP, PIN. Tiap kolom tambahan adalah
 * satu alasan lagi bagi orang yang sedang berdiri di depan TV untuk menyerah
 * dan memanggil kasir.
 */
class RegisterCustomerAction
{
    public function handle(string $name, string $phone, string $pin): Customer
    {
        $normalised = CustomerPhone::normalise($phone);

        if ($normalised === null) {
            throw ValidationException::withMessages([
                'phone' => 'Nomor HP tidak dikenali. Contoh: 081234567890',
            ]);
        }

        if (! preg_match('/^\d{6}$/', $pin)) {
            throw ValidationException::withMessages([
                'pin' => 'PIN harus 6 angka.',
            ]);
        }

        // PIN yang seluruhnya sama atau berurutan ditolak: itu tebakan pertama
        // siapa pun, dan akun ini memegang saldo sungguhan.
        if (preg_match('/^(\d)\1{5}$/', $pin) || in_array($pin, ['123456', '654321'], true)) {
            throw ValidationException::withMessages([
                'pin' => 'PIN terlalu mudah ditebak. Pilih kombinasi lain.',
            ]);
        }

        if (Customer::query()->where('phone', $normalised)->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'Nomor ini sudah punya akun. Silakan masuk.',
            ]);
        }

        return Customer::create([
            'name' => trim($name),
            'phone' => $normalised,
            'pin_hash' => $pin,
            'balance' => 0,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
    }
}
