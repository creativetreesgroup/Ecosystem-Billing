<?php

namespace App\Policies;

use App\Domain\Billing\PaymentStatus;
use App\Models\Payment;
use App\Models\User;

/**
 * Memverifikasi bukti transfer adalah pekerjaan KASIR — dialah yang membuka
 * mutasi rekening saat pelanggan berdiri di depannya. Owner ikut bisa, karena
 * ia juga yang menutup kas.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Payment $payment): bool
    {
        return true;
    }

    /**
     * Hanya yang benar-benar menunggu verifikasi. Pembayaran tunai yang sudah
     * diterima kasir tidak punya apa pun untuk diverifikasi, dan menyediakan
     * tombolnya hanya mengundang penekanan yang tidak berarti.
     */
    public function verify(User $user, Payment $payment): bool
    {
        return $payment->status === PaymentStatus::AwaitingVerification;
    }

    public function reject(User $user, Payment $payment): bool
    {
        return $this->verify($user, $payment);
    }

    /**
     * Pembayaran tidak pernah dibuat manual dari panel: ia lahir dari
     * penyelesaian sesi atau dari pembayaran mandiri pelanggan. Baris yang
     * diketik sendiri berarti pemasukan tanpa sesi yang menjelaskannya.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    /**
     * Jejak pembayaran tidak boleh hilang — termasuk percobaan yang gagal,
     * karena justru itu yang dicari saat ada sengketa nominal.
     */
    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}
