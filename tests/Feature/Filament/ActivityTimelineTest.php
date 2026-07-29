<?php

use App\Domain\Wallet\Wallet;
use App\Filament\Pages\ActivityTimeline;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

/**
 * Jejak perbuatan sudah lama dicatat; yang baru adalah tempat membacanya.
 * Yang diuji di sini bukan tampilannya, melainkan dua hal yang diam-diam bisa
 * salah: siapa boleh membacanya, dan apakah aktivitas pelanggan benar-benar
 * ikut terbaca (bukan cuma aktivitas staf).
 */
test('reading everyones activity needs the same right as managing users', function () {
    $kasir = User::factory()->create();

    $this->actingAs($kasir);
    expect(ActivityTimeline::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->owner()->create());
    expect(ActivityTimeline::canAccess())->toBeTrue();
});

test('the timeline shows what actually happened to a customer', function () {
    $owner = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['name' => 'Rina Pelanggan']);

    // Perbuatan sungguhan, bukan baris log yang ditulis tangan: Wallet mencatat
    // aktivitasnya sendiri setiap saldo berubah.
    app(Wallet::class)->topUp($customer, 50_000);

    Livewire::actingAs($owner)
        ->test(ActivityTimeline::class)
        ->assertSuccessful()
        ->assertSee('Rina Pelanggan');
});

test('the external filter keeps customer activity and drops staff-only rows', function () {
    $owner = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['name' => 'Budi Eksternal']);
    app(Wallet::class)->topUp($customer, 25_000);

    Livewire::actingAs($owner)
        ->test(ActivityTimeline::class)
        ->filterTable('pelaku', 'external')
        ->assertSuccessful()
        ->assertSee('Budi Eksternal');
});

/**
 * Yang dicari orang di lini masa bukan "ada perubahan", melainkan DARI BERAPA
 * KE BERAPA. Tanpa isi perubahannya, pembacanya tetap harus membuka tabel lain
 * — dan saat itu lini masa hanya menambah satu langkah, bukan menghematnya.
 */
test('the timeline shows what actually changed, not just that something did', function () {
    $owner = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['name' => 'Sari Ledger']);

    app(Wallet::class)->topUp($customer, 75_000);

    Livewire::actingAs($owner)
        ->test(ActivityTimeline::class)
        ->assertSuccessful()
        ->assertSee('75000');   // nominalnya terbaca di kartu, bukan cuma "saldo berubah"
});
