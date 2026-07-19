<?php

namespace App\Domain\Devices;

/**
 * Magic packet WoL (broadcast UDP port 9) — beberapa Android TV (Sony/TCL/
 * Coocaa dkk) menjaga network chip tetap hidup saat "mati" tapi tidak selalu
 * merespons media_player.turn_on Home Assistant dari kondisi itu tanpa
 * dibangunkan dulu lewat WoL. Best-effort: dipanggil sebelum perintah HA,
 * kegagalannya tidak pernah menggagalkan perintah utama (lihat HomeAssistantDriver).
 */
final class WakeOnLan
{
    public static function send(string $macAddress, string $broadcastAddress = '255.255.255.255', int $port = 9): bool
    {
        $mac = self::packMac($macAddress);

        if ($mac === null) {
            return false;
        }

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if ($socket === false) {
            return false;
        }

        try {
            socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);

            $magicPacket = str_repeat(chr(0xFF), 6).str_repeat($mac, 16);

            return socket_sendto($socket, $magicPacket, strlen($magicPacket), 0, $broadcastAddress, $port) !== false;
        } finally {
            socket_close($socket);
        }
    }

    private static function packMac(string $macAddress): ?string
    {
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $macAddress);

        if (strlen($hex) !== 12) {
            return null;
        }

        return hex2bin($hex);
    }
}
