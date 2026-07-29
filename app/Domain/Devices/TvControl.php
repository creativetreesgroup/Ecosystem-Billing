<?php

namespace App\Domain\Devices;

use App\Models\Unit;

interface TvControl
{
    public function powerOn(Unit $unit): CommandResult;

    public function powerOff(Unit $unit): CommandResult;

    /**
     * Tampilkan layar QR menganggur DI TV (agar pelanggan berikutnya bisa
     * memindai). Dipanggil saat sesi berakhir.
     */
    public function showIdleScreen(Unit $unit): CommandResult;

    /**
     * Bersihkan layar QR supaya TV kembali ke input game. Dipanggil saat sesi
     * dimulai. Beralih ke input HDMI PS5-nya sendiri bergantung TV/HA (§14) —
     * yang dijamin di sini hanya QR-nya turun.
     */
    public function clearScreen(Unit $unit): CommandResult;

    public function state(Unit $unit): PowerState;

    public function supports(Unit $unit, Capability $capability): bool;

    public function notify(Unit $unit, string $message): CommandResult;
}
