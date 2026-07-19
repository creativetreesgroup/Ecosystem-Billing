<?php

namespace App\Domain\Devices;

/**
 * Format topic MQTT standar firmware Tasmota. Dipakai di dua sisi:
 * TasmotaDriver::publish() menulis lewat command(), MqttBridgeListen
 * membaca lewat power()/availability() — disatukan di sini supaya kedua
 * sisi tidak bisa drift satu sama lain.
 */
final class TasmotaTopic
{
    public static function command(string $controlRef): string
    {
        return "cmnd/{$controlRef}/POWER";
    }

    public static function power(string $controlRef): string
    {
        return "stat/{$controlRef}/POWER";
    }

    public static function availability(string $controlRef): string
    {
        return "tele/{$controlRef}/LWT";
    }

    public static function controlRefFrom(string $topic): ?string
    {
        return explode('/', $topic)[1] ?? null;
    }
}
