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

// Kanal privat per pelanggan kios — dorongan realtime "pembayaran lunas" ke
// HP-nya. WAJIB lewat guard 'customer' (BUKAN guard web panel): pelanggan kios
// login dengan guard tersendiri, dan tiap orang hanya boleh kanalnya sendiri.
//
// $customer SENGAJA tanpa type-hint (seperti kanal User di atas): type-hint
// model pada argumen pertama memicu implicit binding Laravel dan justru
// membuat otorisasinya selalu gagal. Cast kedua sisi karena nilai wildcard
// datang sebagai string (1 === "1" itu false).
Broadcast::channel('customer.{customerId}', function ($customer, $customerId) {
    return (int) $customer->id === (int) $customerId;
}, ['guards' => ['customer']]);
