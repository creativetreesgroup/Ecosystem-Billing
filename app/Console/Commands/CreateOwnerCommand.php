<?php

namespace App\Console\Commands;

use App\Models\Outlet;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;

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
class CreateOwnerCommand extends Command
{
    protected $signature = 'app:create-owner {--name=} {--email=} {--password=} {--outlet=}';

    protected $description = 'Buat akun owner pertama + outlet default (langkah instalasi produksi).';

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

        $owner = User::create([
            'outlet_id' => $outlet->id,
            'name' => $name,
            'email' => $email,
            'password' => $plainPassword, // cast 'hashed' meng-hash saat disimpan
            'role' => UserRole::Owner,
            'is_active' => true,
        ]);

        $this->info("Owner '{$owner->name}' <{$owner->email}> dibuat di outlet '{$outlet->name}'. Login di /admin.");

        return self::SUCCESS;
    }
}
