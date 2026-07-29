<?php

namespace App\Policies;

use App\Models\User;
use Filament\Changelog\Models\ChangelogEntry;

/**
 * Catatan rilis datang dari plugin, dan plugin tidak membawa policy.
 *
 * Filament MENGIZINKAN secara bawaan ketika policy-nya absen, jadi resource ini
 * terbuka untuk sembilan peran sekaligus — padahal tidak satu pun memiliki
 * izinnya, dan Shield sudah membuat kedua belas izinnya. Halamannya bukan cuma
 * daftar: ia punya halaman Tambah dan Ubah, sehingga kasir mana pun bisa
 * menerbitkan pengumuman yang tampil di panel semua orang.
 *
 * Modelnya milik vendor, jadi Laravel tidak menemukan policy ini lewat
 * penamaan otomatis — pendaftarannya eksplisit di AppServiceProvider.
 *
 * Perilakunya kini sama dengan resource lain: izin yang menentukan, bukan
 * ketiadaan policy. Selama belum ada peran yang diberi izinnya, hanya
 * super_admin yang bisa membukanya — dan itu memang keadaan yang jujur.
 */
class ChangelogEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('ViewAny:ChangelogEntry');
    }

    public function view(User $user, ChangelogEntry $entry): bool
    {
        return $user->checkPermissionTo('View:ChangelogEntry');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('Create:ChangelogEntry');
    }

    public function update(User $user, ChangelogEntry $entry): bool
    {
        return $user->checkPermissionTo('Update:ChangelogEntry');
    }

    public function delete(User $user, ChangelogEntry $entry): bool
    {
        return $user->checkPermissionTo('Delete:ChangelogEntry');
    }

    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo('DeleteAny:ChangelogEntry');
    }

    public function restore(User $user, ChangelogEntry $entry): bool
    {
        return $user->checkPermissionTo('Restore:ChangelogEntry');
    }

    public function forceDelete(User $user, ChangelogEntry $entry): bool
    {
        return $user->checkPermissionTo('ForceDelete:ChangelogEntry');
    }

    public function reorder(User $user): bool
    {
        return $user->checkPermissionTo('Reorder:ChangelogEntry');
    }

    public function replicate(User $user, ChangelogEntry $entry): bool
    {
        return $user->checkPermissionTo('Replicate:ChangelogEntry');
    }
}
