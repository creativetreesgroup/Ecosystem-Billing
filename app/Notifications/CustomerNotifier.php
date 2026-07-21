<?php

namespace App\Notifications;

use App\Models\Customer;

/**
 * Kirim notifikasi WhatsApp ke seorang pelanggan lewat WAHA.
 *
 * Diam bila WAHA belum dikonfigurasi atau pelanggan tak punya nomor: notifikasi
 * ini TAMBAHAN di atas layar kios, bukan penggantinya — ketiadaannya tak boleh
 * menggagalkan alur mana pun. Nomor pelanggan sudah tersimpan seragam (08xxx),
 * WahaClient yang mengubahnya ke chatId.
 */
class CustomerNotifier
{
    public function __construct(private readonly WahaClient $waha) {}

    public function notify(Customer $customer, string $message): void
    {
        if (blank($customer->phone) || ! $this->waha->isConfigured()) {
            return;
        }

        $this->waha->sendText($customer->phone, $message);
    }
}
