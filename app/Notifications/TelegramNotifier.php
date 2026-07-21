<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Http;

/**
 * Kirim notifikasi operasional ke satu chat Telegram owner/staf lewat Bot API.
 *
 * Keluar ke api.telegram.org (§14 melarang koneksi MASUK, bukan keluar — sama
 * seperti poll Midtrans). Token & chat_id hanya di .env. Kalau belum
 * dikonfigurasi, diam saja: Telegram itu tambahan di atas lonceng panel, bukan
 * penggantinya, jadi ketiadaannya tak boleh menggagalkan apa pun.
 */
class TelegramNotifier
{
    public static function send(string $title, string $body): void
    {
        if (! self::isConfigured()) {
            return;
        }

        // strip_tags: body notifikasi Filament bisa memuat markup; Telegram di
        // sini dikirim sebagai teks biasa (tanpa parse_mode) supaya karakter
        // spesial pada nama/nominal tidak merusak pesan.
        $text = trim($title."\n".strip_tags($body));

        Http::timeout(10)
            ->retry(2, 200, throw: false)
            ->asJson()
            ->post('https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendMessage', [
                'chat_id' => config('services.telegram.chat_id'),
                'text' => $text,
                'disable_web_page_preview' => true,
            ]);
    }

    public static function isConfigured(): bool
    {
        return filled(config('services.telegram.bot_token')) && filled(config('services.telegram.chat_id'));
    }
}
