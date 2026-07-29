<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Bukti transfer bisa datang dari ISI SALDO (rental_session_id null), bukan hanya
 * sesi. Membuka "Periksa bukti" untuk pembayaran isi saldo dulu meledak karena
 * mengakses ->rentalSession->unit yang null. Kini judul & konteksnya jatuh ke
 * nama pelanggan + "Isi saldo".
 */
test('reviewing a kiosk top-up transfer proof does not crash on the missing session', function () {
    $kasir = User::factory()->create();
    // Verifikasi bukti transfer milik departemen Keuangan.
    $kasir->syncRoles(['staf_operasional', 'staf_keuangan']);
    $customer = Customer::factory()->create(['name' => 'Budi']);

    $payment = Payment::factory()->transferAwaitingVerification()->create([
        'customer_id' => $customer->id,
        'rental_session_id' => null,
        'amount' => 40_000,
    ]);

    // Modal ter-mount tanpa meledak. Dulu modalHeading mengakses
    // ->rentalSession->unit pada pembayaran isi saldo (rentalSession null) →
    // "read property on null" saat kasir membuka modalnya.
    $action = TestAction::make('review')->table($payment);

    Livewire::actingAs($kasir)->test(ListPayments::class)
        ->mountAction($action)
        ->assertActionMounted($action)
        ->assertHasNoActionErrors();
});

/**
 * Menerima bukti isi saldo tetap menambah saldo pelanggan (lewat
 * ApplySettledPaymentAction) — jalur uangnya utuh, bukan cuma tak-crash.
 */
test('accepting a top-up transfer proof credits the customer wallet', function () {
    $kasir = User::factory()->create();
    // Verifikasi bukti transfer milik departemen Keuangan.
    $kasir->syncRoles(['staf_operasional', 'staf_keuangan']);
    $customer = Customer::factory()->create();

    $payment = Payment::factory()->transferAwaitingVerification()->create([
        'customer_id' => $customer->id,
        'rental_session_id' => null,
        'amount' => 40_000,
    ]);

    Livewire::actingAs($kasir)->test(ListPayments::class)
        ->callAction(TestAction::make('review')->table($payment))
        ->assertHasNoActionErrors();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($customer->fresh()->balance)->toBe(40_000);
});

/**
 * REGRESI: tabel pembayaran dulu membaca unit DAN nama pelanggan lewat
 * rentalSession. Isi saldo dari kios tidak punya sesi sama sekali, jadi seluruh
 * barisnya tampil kosong — dan sejak kios ada, justru itulah mayoritasnya.
 */
test('a wallet top-up is readable in the payments table', function () {
    $owner = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['name' => 'Rina Topup']);

    Payment::create([
        'customer_id' => $customer->id,
        'method' => PaymentMethod::Transfer,
        'status' => PaymentStatus::AwaitingVerification,
        'amount' => 50_000,
    ]);

    Livewire::actingAs($owner)
        ->test(ListPayments::class)
        ->assertSee('Rina Topup')   // bukan "Tanpa nama"
        ->assertSee('Isi saldo');   // bukan sel kosong
});

/**
 * Bukti transfer diunggah PELANGGAN, jadi ukurannya tak bisa diasumsikan:
 * potret dari HP, atau tangkapan layar 16:9 selebar 2000px.
 *
 * Modal ini dulu hanya membatasi TINGGI gambar. Tangkapan layar 16:9 yang
 * diskalakan ke tinggi 320px menjadi ~570px lebar — lebih lebar dari modalnya,
 * sehingga menembus keluar dan menutupi tabel di belakangnya beserta tombol
 * Batal dan Terima. Kasir tidak bisa lagi menekan apa pun tanpa menggulir.
 *
 * Diperiksa dari source, bukan dari HTML: modal Filament dirender terpisah dari
 * halamannya sehingga tidak ikut muncul di ->html(). Pola yang sama dipakai
 * KioskScreenTest untuk menjaga tetapan yang tak terlihat dari hasil render.
 */
test('the transfer proof image is bound to the modal width', function () {
    $view = file_get_contents(resource_path('views/filament/infolists/entries/zoomable-proof.blade.php'));

    expect($view)
        // Wadahnya memotong apa pun yang melewati batas, berapa pun perbesarannya.
        ->toContain('overflow:hidden')
        // Menjaga rasio, supaya nominal pada struk tidak melar dan tetap terbaca.
        ->toContain('object-fit:contain')
        // Perbesaran dibatasi: tanpa batas atas, satu gulir panjang membuat
        // gambar melompat ke ratusan kali dan kasir kehilangan jejak isinya.
        ->toContain('Math.min(4')
        ->toContain('Math.max(1');

    $php = file_get_contents(app_path('Filament/Resources/Payments/Tables/PaymentsTable.php'));

    // Tinggi tetap yang lama justru penyebabnya: ia membiarkan lebar bebas.
    expect($php)->not->toContain('->height(320)');
});
