<?php

namespace App\Policies;

use App\Models\DeviceAlert;
use App\Models\User;

class DeviceAlertPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DeviceAlert $deviceAlert): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        // Alert dibuat sistem lewat driver/reconciliation, bukan dibuat manual lewat UI.
        return false;
    }

    public function update(User $user, DeviceAlert $deviceAlert): bool
    {
        return false;
    }

    public function acknowledge(User $user, DeviceAlert $deviceAlert): bool
    {
        return true;
    }

    public function delete(User $user, DeviceAlert $deviceAlert): bool
    {
        return false;
    }

    public function restore(User $user, DeviceAlert $deviceAlert): bool
    {
        return false;
    }

    public function forceDelete(User $user, DeviceAlert $deviceAlert): bool
    {
        return false;
    }
}
