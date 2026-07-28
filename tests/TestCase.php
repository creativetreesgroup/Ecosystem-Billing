<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Peran & izin disemai sekali untuk seluruh suite.
     *
     * Otorisasi kini dibaca dari database, jadi test yang berjalan tanpa izin
     * apa pun bukan test yang jujur — ia menguji panel kosong, bukan panel
     * yang dipakai orang. Ini seeder yang sama persis dengan yang dijalankan
     * saat instalasi.
     */
    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    /**
     * Kunci suite ke database test, lalu buktikan kuncinya memang terpasang.
     *
     * `force="true"` di phpunit.xml TIDAK cukup: PHPUnit menerapkannya lewat
     * putenv() saja, sedangkan Laravel membaca $_SERVER lebih dulu — dan di
     * dalam Docker $_SERVER sudah diisi `env_file: .env` dengan nama database
     * APLIKASI. Tanpa dua baris di bawah, RefreshDatabase menjalankan
     * migrate:fresh (DROP seluruh tabel) di database produksi tanpa satu pun
     * pesan salah, karena koneksinya memang berhasil.
     *
     * Penjagaan terakhirnya adalah pengecekan nama: kalau suatu hari mekanisme
     * env berubah lagi, suite berhenti dengan keras alih-alih menghapus data
     * yang tidak bisa dikembalikan.
     */
    public function createApplication(): Application
    {
        if (($database = getenv('DB_DATABASE')) !== false) {
            $_SERVER['DB_DATABASE'] = $_ENV['DB_DATABASE'] = $database;
        }

        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $name = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! str_ends_with($name, '_test')) {
            throw new RuntimeException(
                "Test menolak berjalan di database '{$name}': namanya tidak berakhiran '_test'. ".
                'Suite ini menjalankan migrate:fresh — arahkan DB_DATABASE ke database test.'
            );
        }

        return $app;
    }
}
