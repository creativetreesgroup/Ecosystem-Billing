<?php

namespace App\Console\Commands;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Membuat akun owner pertama (dan outlet default bila belum ada) — langkah
 * instalasi produksi.
 *
 * Menggantikan `make:filament-user`, yang TIDAK bisa dipakai di sistem ini:
 * `users.role` NOT NULL tanpa default dan `users.outlet_id` FK NOT NULL ke
 * `outlets` yang masih kosong, sementara make:filament-user hanya mengisi
 * name/email/password → INSERT gagal di MySQL strict (error 1364) dan instalasi
 * baru tak akan pernah punya user untuk login. Perintah ini mengisi role +
 * outlet dengan benar, jadi hasilnya deterministik di setiap instalasi.
 */
#[Signature('app:create-owner {--name=} {--email=} {--password=} {--outlet=}')]
#[Description('Buat akun owner pertama + outlet default (langkah instalasi produksi).')]
class CreateOwner extends Command
{
    public function handle(): int
    {
        $outlet = Outlet::query()->orderBy('id')->first()
            ?? Outlet::create([
                'name' => $this->option('outlet') ?: 'Outlet Utama',
                'timezone' => config('app.display_timezone', 'Asia/Jakarta'),
                'is_active' => true,
            ]);

        $name = $this->option('name') ?: text('Nama owner', required: true);

        $email = $this->option('email') ?: text(
            label: 'Email owner',
            required: true,
            validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Email tidak valid.',
        );

        if (User::query()->where('email', $email)->exists()) {
            $this->error("Email {$email} sudah dipakai.");

            return self::FAILURE;
        }

        $plainPassword = $this->option('password') ?: password('Password owner (min. 8 karakter)', required: true);

        if (strlen($plainPassword) < 8) {
            $this->error('Password minimal 8 karakter.');

            return self::FAILURE;
        }

        // Satu transaksi untuk user + perannya. assignRole() melempar bila
        // peran 'super_admin' belum ada di DB, dan tanpa transaksi kegagalan itu
        // meninggalkan owner tanpa peran: percobaan ulang lalu ditolak "email
        // sudah dipakai", dan instalasi terjebak — tak bisa maju, tak bisa
        // diulang, tanpa membersihkan tabel dengan tangan.
        $owner = DB::transaction(function () use ($outlet, $name, $email, $plainPassword): User {
            $owner = User::create([
                'outlet_id' => $outlet->id,
                'name' => $name,
                'email' => $email,
                'password' => $plainPassword, // cast 'hashed' meng-hash saat disimpan
                'is_active' => true,
            ]);

            // Otorisasi dibaca dari Shield, bukan dari kolom role. Tanpa baris
            // ini instalasi baru menghasilkan owner yang bisa masuk panel tapi
            // tidak melihat apa pun — pemblokir instalasi yang hanya ketahuan
            // saat orang pertama mencoba memakainya.
            $owner->assignRole(config('filament-shield.super_admin.name', 'super_admin'));

            return $owner;
        });

        $this->info("Owner '{$owner->name}' <{$owner->email}> dibuat di outlet '{$outlet->name}'. Login di /admin.");

        return self::SUCCESS;
    }
}
