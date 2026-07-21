<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Settings\SettingKey;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Unit;
use Livewire\Livewire;

/**
 * Layar pembayaran kios diuji lewat render komponen: penataan piksel dilihat
 * mata, tapi "apa yang tertulis di layar uang" tidak boleh cuma diperiksa
 * sekali dengan tangan lalu berubah diam-diam.
 */
beforeEach(function () {
    $this->unit = Unit::factory()->create();
    $this->customer = Customer::factory()->create();

    Setting::put(SettingKey::TransferBankName, 'MANDIRI');
    Setting::put(SettingKey::TransferAccountNumber, '72873793932');
    Setting::put(SettingKey::TransferAccountHolder, 'M HALFIRZZHATULLAH');
});

test('the transfer screen shows the account, A/N, and a modern file picker', function () {
    $payment = Payment::create([
        'customer_id' => $this->customer->id,
        'method' => PaymentMethod::Transfer,
        'status' => PaymentStatus::Pending,
        'amount' => 50_000,
    ]);

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('paymentId', $payment->id)
        ->assertSee('Transfer ke rekening')
        ->assertSee('MANDIRI')
        ->assertSee('72873793932')
        ->assertSee('A/N')
        ->assertSee('M HALFIRZZHATULLAH')
        // Pemilih file modern, bukan input mentah "Pilih File".
        ->assertSee('Pilih foto bukti transfer')
        ->assertDontSee('Tidak ada file yang dipilih');
});

/**
 * Konfirmasi lunas HARUS benar-benar tampil, bukan berkedip lalu hilang: layar
 * "berhasil" muncul dari status Paid dan pelanggan menutupnya sendiri.
 */
test('a settled payment shows a clear success screen the customer dismisses', function () {
    $payment = Payment::create([
        'customer_id' => $this->customer->id,
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Paid,
        'amount' => 50_000,
        'verified_at' => now(),
    ]);

    $component = Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('paymentId', $payment->id)
        ->assertSee('Pembayaran berhasil')
        ->assertSee('Rp 50.000');

    // Polling TIDAK boleh menutup layar sukses sendiri — pelanggan yang menutup.
    $component->call('refreshStatus')->assertSee('Pembayaran berhasil');

    $component->call('finishPayment')->assertDontSee('Pembayaran berhasil');
});

/**
 * KEAMANAN: paymentId properti publik yang bisa di-tamper. Pelanggan TIDAK boleh
 * melihat atau menyentuh pembayaran milik orang lain hanya dengan menunjuk ID-nya
 * (payment() difilter ke pembayaran pelanggan yang login).
 */
test('a customer cannot view another customers payment by tampering paymentId', function () {
    $other = Customer::factory()->create();
    $othersPayment = Payment::create([
        'customer_id' => $other->id,
        'method' => PaymentMethod::Transfer,
        'status' => PaymentStatus::Pending,
        'amount' => 999_000,
    ]);

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('paymentId', $othersPayment->id)
        // Tidak membocorkan nominal maupun layar pembayaran orang lain.
        ->assertDontSee('Rp 999.000')
        ->assertDontSee('Transfer ke rekening');

    // Dan buktinya tak tersentuh (uploadProof membaca payment() yang sama).
    expect($othersPayment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($othersPayment->fresh()->proof_path)->toBeNull();
});

/**
 * Layar pembayaran menggantung harus punya jalan keluar: membatalkan tagihan
 * QRIS/transfer yang belum dibayar menandainya Expired (tak ada QR hidup yang
 * bisa terlanjur dibayar) dan kembali ke tab isi saldo.
 */
test('cancelling a pending payment expires it and leaves the payment screen', function () {
    $payment = Payment::create([
        'customer_id' => $this->customer->id,
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Pending,
        'amount' => 50_000,
    ]);

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('paymentId', $payment->id)
        ->assertSee('Bayar dengan QRIS')
        ->call('cancelPayment')
        ->assertDontSee('Bayar dengan QRIS');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Expired);
});
