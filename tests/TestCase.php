<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
}
