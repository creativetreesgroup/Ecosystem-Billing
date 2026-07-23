<?php

namespace App\Domain\Menu;

use App\Domain\Settings\SettingKey;
use App\Models\Setting;

/**
 * Kapan dapur menerima pesanan.
 *
 * Satu tempat yang menjawabnya untuk semua: layar kios memakainya untuk
 * memutuskan menampilkan menu atau pemberitahuan, dan PlaceMenuOrderAction
 * memakainya untuk MENOLAK. Kalau jawabannya dihitung terpisah di dua tempat,
 * cepat atau lambat layar bilang tutup sementara jalur uangnya masih menerima.
 */
final class MenuServiceHours
{
    public static function status(): MenuServiceStatus
    {
        // Saklar pemilik menang atas jam berapa pun — itu gunanya ada saklar.
        if ((int) Setting::get(SettingKey::MenuOrderingEnabled) !== 1) {
            return MenuServiceStatus::Disabled;
        }

        if (! self::withinOpeningHours()) {
            return MenuServiceStatus::Closed;
        }

        if (self::within(self::time(SettingKey::MenuBreakStartTime), self::time(SettingKey::MenuBreakEndTime))) {
            return MenuServiceStatus::Break;
        }

        return MenuServiceStatus::Open;
    }

    public static function isOpen(): bool
    {
        return self::status()->isOpen();
    }

    /**
     * Kalimat untuk pelanggan — null saat buka.
     *
     * Selalu menyebut PUKUL BERAPA layanannya kembali. "Sedang istirahat" tanpa
     * itu membuat orang menunggu tanpa tahu sampai kapan, lalu bertanya ke
     * kasir; menyebutkan jamnya menghapus seluruh percakapan itu.
     */
    public static function notice(): ?string
    {
        return match (self::status()) {
            MenuServiceStatus::Open => null,
            MenuServiceStatus::Break => 'Dapur sedang istirahat. Pesanan dibuka lagi pukul '
                .self::time(SettingKey::MenuBreakEndTime).'.',
            MenuServiceStatus::Closed => 'Pemesanan makanan & minuman sedang tutup. Buka pukul '
                .self::time(SettingKey::MenuOpenTime).'.',
            MenuServiceStatus::Disabled => 'Pemesanan makanan & minuman sedang dimatikan. Silakan tanya kasir.',
        };
    }

    /** Jam buka–tutup; kosong atau sama persis berarti 24 jam. */
    private static function withinOpeningHours(): bool
    {
        $open = self::time(SettingKey::MenuOpenTime);
        $close = self::time(SettingKey::MenuCloseTime);

        if ($open === '' || $close === '' || $open === $close) {
            return true;
        }

        return self::within($open, $close);
    }

    /**
     * Apakah sekarang berada di dalam rentang $start–$end (akhir eksklusif).
     *
     * Rentang yang MELEWATI TENGAH MALAM ditangani terpisah: rental PS lumrah
     * buka 10:00–02:00, dan perbandingan yang naif menganggapnya tutup
     * sepanjang hari karena "10:00" > "02:00".
     */
    private static function within(string $start, string $end): bool
    {
        if ($start === '' || $end === '' || $start === $end) {
            return false;
        }

        $now = now()->format('H:i');

        return $start < $end
            ? ($now >= $start && $now < $end)
            : ($now >= $start || $now < $end);
    }

    /**
     * Nilai jam dinormalkan ke "HH:MM".
     *
     * Nilainya diketik manusia lewat panel, dan perbandingannya di sini bersifat
     * leksikal: "9:00" akan dinilai LEBIH BESAR dari "10:00" dan membalik seluruh
     * logika buka-tutup. Yang tidak berbentuk jam dianggap kosong (= tidak diatur).
     *
     * Polanya sengaja tidak dijangkar di awal: pemilih waktu Filament bisa
     * menyimpan "2026-07-23 15:00:00", dan menolak bentuk itu berarti jam yang
     * jelas-jelas diisi pemilik diam-diam dianggap kosong (= buka 24 jam).
     */
    private static function time(SettingKey $key): string
    {
        $raw = trim((string) Setting::get($key));

        if (! preg_match('/(\d{1,2}):([0-5]\d)/', $raw, $matches)) {
            return '';
        }

        $hour = (int) $matches[1];

        return $hour > 23 ? '' : sprintf('%02d:%02d', $hour, (int) $matches[2]);
    }
}
