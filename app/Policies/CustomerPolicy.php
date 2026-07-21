<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Models\UserRole;

/**
 * Member dilihat & diisi saldonya oleh KASIR (yang berhadapan dengan mereka di
 * meja) dan owner. Yang tidak boleh siapa pun: membuat member dari panel
 * (mereka mendaftar sendiri di kios) atau menghapusnya (jejak saldo hilang).
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        return true;
    }

    /** Nama, keaktifan, dan reset PIN — lihat CustomerForm. */
    public function update(User $user, Customer $customer): bool
    {
        return true;
    }

    /**
     * Koreksi saldo manual (termasuk membuat saldo minus) hanya owner. Kasir
     * cukup Isi Saldo Tunai untuk uang yang benar-benar diterima; mengurangi
     * atau menembus nol adalah keputusan pemilik, dan jejaknya harus jelas.
     */
    public function adjustBalance(User $user, Customer $customer): bool
    {
        return $user->role === UserRole::Owner;
    }

    /** Member lahir dari pendaftaran mandiri di kios, bukan diketik kasir. */
    public function create(User $user): bool
    {
        return false;
    }

    /** Tidak pernah: member memegang buku besar saldo. */
    public function delete(User $user, Customer $customer): bool
    {
        return false;
    }
}
