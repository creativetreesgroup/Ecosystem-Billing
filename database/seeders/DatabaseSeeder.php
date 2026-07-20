<?php

namespace Database\Seeders;

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\IntegrationKey;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\Integration;
use App\Models\Outlet;
use App\Models\Package;
use App\Models\RentalSession;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $outlet = Outlet::create([
            'name' => 'Creative Trees Billing Game - Outlet Utama',
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $owner = User::factory()->owner()->create([
            'outlet_id' => $outlet->id,
            'name' => 'Owner',
            'email' => 'owner@creativetrees.test',
        ]);

        $kasir = User::factory()->create([
            'outlet_id' => $outlet->id,
            'name' => 'Kasir',
            'email' => 'kasir@creativetrees.test',
        ]);

        Setting::create(['key' => 'billing_increment_minutes', 'value' => ['minutes' => 1]]);
        Setting::create(['key' => 'warning_before_minutes', 'value' => ['minutes' => 5]]);

        // Barisnya dibuat KOSONG, bukan diisi contoh: sampai pemilik menempel
        // tokennya sendiri dari Home Assistant, sistem tetap memakai .env.
        // Baris berisi token palsu justru akan menang atas .env dan membuat
        // integrasi yang tadinya jalan mendadak gagal otentikasi.
        Integration::create([
            'key' => IntegrationKey::HomeAssistant,
            'base_url' => null,
            'token' => null,
            'is_active' => true,
        ]);

        $nonVip = UnitType::create([
            'outlet_id' => $outlet->id,
            'name' => 'Non-VIP',
            'hourly_rate' => 5000,
            'sort_order' => 1,
        ]);

        $vip = UnitType::create([
            'outlet_id' => $outlet->id,
            'name' => 'VIP',
            'hourly_rate' => 8000,
            'sort_order' => 2,
        ]);

        $sultan = UnitType::create([
            'outlet_id' => $outlet->id,
            'name' => 'Sultan',
            'hourly_rate' => 12000,
            'sort_order' => 3,
        ]);

        foreach ([$nonVip, $vip, $sultan] as $unitType) {
            foreach ([1, 2, 3, 5] as $hours) {
                Package::create([
                    'unit_type_id' => $unitType->id,
                    'name' => "{$hours} Jam",
                    'duration_minutes' => $hours * 60,
                    'price' => $hours * $unitType->hourly_rate,
                    'is_active' => true,
                ]);
            }
        }

        // Driver dicampur untuk membuktikan abstraksi TvControl bekerja untuk
        // ketiganya, meski deployment nyata saat ini seluruhnya Android TV (home_assistant).
        $units = [
            // SEMUA unit lahir sebagai Manual, tanpa control_ref.
            //
            // Seeder ini dulu mengarang entity seperti
            // "media_player.ps_02_androidtv" supaya datanya terlihat lengkap.
            // Akibatnya di UAT: tiga unit menunjuk perangkat yang TIDAK ADA di
            // Home Assistant, dan tiap kali sesinya dimulai perintah TV gagal
            // tanpa ada yang menyadari. Data contoh tidak boleh berpura-pura
            // menjadi perangkat sungguhan — operator memasangkannya sendiri
            // lewat form unit, yang memang sudah menyediakan pemindaian.
            ['code' => 'PS-01', 'unit_type_id' => $nonVip->id],
            ['code' => 'PS-02', 'unit_type_id' => $nonVip->id],
            ['code' => 'PS-03', 'unit_type_id' => $nonVip->id],
            ['code' => 'PS-04', 'unit_type_id' => $vip->id],
            ['code' => 'PS-05', 'unit_type_id' => $vip->id],
            ['code' => 'PS-06', 'unit_type_id' => $sultan->id],
        ];

        $createdUnits = collect($units)->map(fn (array $data) => Unit::create([
            'outlet_id' => $outlet->id,
            'unit_type_id' => $data['unit_type_id'],
            'code' => $data['code'],
            'control_driver' => ControlDriver::Manual,
            'control_ref' => null,
            'is_active' => true,
        ]));

        // Beberapa sesi historis untuk demo laporan.
        foreach (range(1, 8) as $i) {
            $unit = $createdUnits->random();
            $hours = fake()->randomElement([1, 2, 3]);
            // Jam operasional ditulis di zona outlet lalu dikonversi ke UTC untuk
            // disimpan — kalau tidak, sesi "jam 17" tersimpan 17:00 UTC yang
            // berarti tengah malam WIB, dan laporan melaporkan jam sibuk 00:00.
            $tz = config('app.display_timezone', config('app.timezone'));
            $startedAt = now($tz)
                ->subDays(fake()->numberBetween(1, 14))
                ->setTime(fake()->numberBetween(10, 20), 0)
                ->utc();
            $amount = $hours * $unit->unitType->hourly_rate;

            RentalSession::create([
                'unit_id' => $unit->id,
                'opened_by' => $kasir->id,
                'customer_name' => fake()->firstName(),
                'type' => SessionType::Open,
                'started_at' => $startedAt,
                'ended_at' => $startedAt->copy()->addHours($hours),
                'status' => SessionStatus::Completed,
                'expiry_token' => (string) Str::uuid(),
                'base_amount' => $amount,
                'total_amount' => $amount,
                'payment_method' => fake()->randomElement(PaymentMethod::cases()),
                'paid_at' => $startedAt->copy()->addHours($hours),
            ]);
        }
    }
}
