<?php

namespace App\Domain\Devices;

/**
 * Satu perangkat yang menjawab pemindaian jaringan lokal.
 *
 * Sengaja BUKAN sesuatu yang bisa dikontrol: hasil pemindaian hanya bercerita
 * "perangkat ini ada di jaringan kita". Menyalakan/mematikan TV tetap lewat
 * driver (Home Assistant / Tasmota), karena SSDP tidak memberi jalur kontrol
 * yang sama untuk semua merek.
 */
final class DiscoveredDevice
{
    public function __construct(
        public readonly string $ip,
        public readonly ?string $name = null,
        public readonly ?string $model = null,
        public readonly ?string $manufacturer = null,
        public readonly ?string $server = null,
        public readonly ?string $location = null,
        public readonly ?string $mac = null,
    ) {}

    /**
     * Baris siap tampil: "TCL Smart TV (192.168.100.7)".
     */
    public function labelWithIp(): string
    {
        return ($this->name ?: $this->model ?: 'Perangkat tanpa nama')." ({$this->ip})";
    }

    /**
     * Nama yang paling masuk akal dibaca operator, dengan mundur bertahap:
     * nama ramah dari perangkat → model → identitas software → IP.
     */
    public function label(): string
    {
        $name = $this->name ?: $this->model ?: $this->server ?: $this->ip;

        return trim($name).' — '.$this->ip;
    }

    /**
     * Apakah ini kemungkinan besar TV, bukan router/printer/NAS.
     *
     * Router pun menjawab SSDP (di jaringan ini: "Portable SDK for UPnP
     * devices"), jadi tanpa saringan ini daftarnya menyesatkan. Penandanya
     * dipilih yang lintas merek: DLNA MediaRenderer dipakai Samsung/LG/Sony,
     * Chromecast/DIAL dipakai Android TV & merek lokal seperti Coocaa/TCL.
     */
    public function looksLikeTelevision(): bool
    {
        $haystack = strtolower(implode(' ', array_filter([
            $this->server, $this->model, $this->name, $this->manufacturer,
        ])));

        foreach (['chromecast', 'dlnadoc', 'mediarenderer', 'android tv', 'googletv', 'smarttv', 'bravia', 'aquos', 'coocaa', 'tcl', 'samsung', 'lg elec', 'webos', 'philips', 'hisense', 'sharp', 'panasonic', 'polytron'] as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }
}
