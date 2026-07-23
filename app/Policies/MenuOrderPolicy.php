<?php

namespace App\Policies;

use App\Models\MenuOrder;
use App\Models\User;

/**
 * Antrean pesanan dikerjakan KASIR, bukan cuma owner — merekalah yang menyiapkan
 * dan mengantar. Tapi pesanan tidak pernah dibuat/dihapus dari panel: sumbernya
 * kios pelanggan, dan menghapusnya akan menghapus jejak uang yang sudah ditarik.
 */
class MenuOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('ViewAny:MenuOrder');
    }

    public function view(User $user, MenuOrder $menuOrder): bool
    {
        return $user->checkPermissionTo('View:MenuOrder');
    }

    /** Memajukan status & membatalkan (refund) — keduanya lewat izin update. */
    public function update(User $user, MenuOrder $menuOrder): bool
    {
        return $user->checkPermissionTo('Update:MenuOrder');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, MenuOrder $menuOrder): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, MenuOrder $menuOrder): bool
    {
        return false;
    }
}
