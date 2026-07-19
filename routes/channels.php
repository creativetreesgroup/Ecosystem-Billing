<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Semua user panel yang aktif boleh mendengarkan perubahan state unit & sesi.
Broadcast::channel('panel.units', function (User $user) {
    return $user->is_active;
});
