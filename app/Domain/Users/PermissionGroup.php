<?php

namespace App\Domain\Users;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * Departemen tempat sebuah peran bekerja.
 *
 * Shield menghasilkan 199 izin — daftar sepanjang itu tidak bisa dibaca
 * manusia, dan peran yang disusun dari daftar tak terbaca adalah peran yang
 * diberi terlalu banyak karena lebih cepat mencentang semuanya. Grup ini yang
 * membuatnya bisa dibaca: pemilik memilih DEPARTEMEN dulu, lalu hanya melihat
 * izin milik departemen itu.
 *
 * Satu entitas hanya boleh berada di SATU grup. Kalau tidak, "Admin Keuangan
 * boleh semua di Keuangan" diam-diam berarti ia juga menyentuh modul lain —
 * dan itu justru kebalikan dari alasan grup ini dibuat. PermissionGroupTest
 * yang menjaganya, termasuk untuk resource yang ditambahkan nanti.
 */
enum PermissionGroup: string implements HasColor, HasLabel
{
    case Keuangan = 'keuangan';
    case Operasional = 'operasional';
    case Maintenance = 'maintenance';
    case DataMaster = 'data-master';
    case Sistem = 'sistem';

    public function getLabel(): string
    {
        return match ($this) {
            self::Keuangan => 'Keuangan',
            self::Operasional => 'Operasional',
            self::Maintenance => 'Maintenance',
            self::DataMaster => 'Data Master',
            self::Sistem => 'Sistem',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Keuangan => 'success',
            self::Operasional => 'info',
            self::Maintenance => 'warning',
            self::DataMaster => 'gray',
            self::Sistem => 'danger',
        };
    }

    /** Kalimat yang menjelaskan BATAS grup, bukan mengulang namanya. */
    public function description(): string
    {
        return match ($this) {
            self::Keuangan => 'Uang masuk dan laporannya: verifikasi pembayaran, voucher & promo, serta laporan penjualan.',
            self::Operasional => 'Lantai outlet sehari-hari: sesi rental berjalan, pesanan makanan, dan data pelanggan.',
            self::Maintenance => 'Perangkat keras dan jaringannya: unit & TV, alert perangkat, serta integrasi Home Assistant/MQTT.',
            self::DataMaster => 'Katalog yang jarang berubah: paket harga, tipe unit, kategori & item menu, dan data outlet.',
            self::Sistem => 'Kendali sistem: pengguna, peran & izin, dan pengaturan aplikasi. Grup paling berbahaya — isinya mengatur semua grup lain.',
        };
    }

    /**
     * Entitas Shield (model resource, halaman, widget) milik grup ini.
     *
     * @return list<string>
     */
    public function entities(): array
    {
        return match ($this) {
            self::Keuangan => [
                'Payment', 'Discount',
                'SalesReport', 'SalesRevenueChart', 'SalesPaymentMixChart', 'SalesStatsWidget',
            ],
            self::Operasional => [
                'RentalSession', 'MenuOrder', 'Customer',
                'UnitGridWidget', 'OutletOverviewWidget',
            ],
            self::Maintenance => [
                'Unit', 'DeviceAlert', 'Integration',
            ],
            self::DataMaster => [
                'Package', 'UnitType', 'MenuCategory', 'MenuItem', 'Outlet',
            ],
            self::Sistem => [
                'User', 'Role', 'Setting',
            ],
        };
    }

    /**
     * Izin bawaan untuk peran STAF di grup ini — ditulis satu per satu, sengaja.
     *
     * Versi pertama menurunkannya dengan rumus ("semua izin grup kecuali yang
     * merusak"), dan rumus itu diam-diam melebarkan akses: staf lantai jadi
     * bisa membuka tabel sesi rental yang sebelumnya khusus pemilik. Matriks
     * akses adalah satu-satunya bagian sistem yang harus BISA DIBACA UTUH oleh
     * manusia — jadi ia dieja, bukan dihitung.
     *
     * Admin grup tetap memakai rumus (seluruh izin grupnya), dan itu aman:
     * batas kekuasaannya sudah dijaga oleh batas grupnya sendiri.
     *
     * @return list<string>
     */
    public function staffPermissionNames(): array
    {
        return match ($this) {
            // Verifikasi bukti transfer memang pekerjaan staf — dialah yang
            // membuka mutasi rekening saat pelanggan berdiri di depannya.
            self::Keuangan => [
                'ViewAny:Payment', 'View:Payment', 'Verify:Payment',
            ],
            // Pesanan makanan boleh diproses; sesi rental tidak dibuka di sini
            // karena kasir bekerja dari grid unit, bukan dari tabel sesi.
            self::Operasional => [
                'ViewAny:MenuOrder', 'View:MenuOrder', 'Update:MenuOrder',
                // Update:Customer adalah gerbang top-up tunai di panel —
                // menerima uang di laci lalu menambah saldo adalah pekerjaan
                // inti kasir, bukan wewenang admin.
                'ViewAny:Customer', 'View:Customer', 'Update:Customer',
                'View:UnitGridWidget', 'View:OutletOverviewWidget',
            ],
            // Melihat unit dan menandai alert sudah ditangani; mengubah unit
            // dan menyentuh integrasi tetap milik admin.
            self::Maintenance => [
                'ViewAny:Unit', 'View:Unit',
                'ViewAny:DeviceAlert', 'View:DeviceAlert', 'Acknowledge:DeviceAlert',
            ],
            // Katalog harga dan kendali sistem tidak punya tingkat staf: yang
            // mengubahnya mengubah uang atau akses, dan itu keputusan admin.
            self::DataMaster, self::Sistem => [],
        };
    }

    /** Nama semua izin milik grup ini, dibaca dari yang benar-benar ada di database. */
    public function permissionNames(): Collection
    {
        $entities = $this->entities();

        return Permission::query()
            ->pluck('name')
            ->filter(fn (string $name): bool => in_array(self::entityOf($name), $entities, true))
            ->values();
    }

    /** Bagian subjek dari nama izin: "ViewAny:Payment" → "Payment". */
    public static function entityOf(string $permissionName): string
    {
        return str_contains($permissionName, ':')
            ? explode(':', $permissionName, 2)[1]
            : $permissionName;
    }

    /** Grup pemilik sebuah entitas, null bila belum terdaftar di mana pun. */
    public static function for(string $entity): ?self
    {
        foreach (self::cases() as $group) {
            if (in_array($entity, $group->entities(), true)) {
                return $group;
            }
        }

        return null;
    }
}
