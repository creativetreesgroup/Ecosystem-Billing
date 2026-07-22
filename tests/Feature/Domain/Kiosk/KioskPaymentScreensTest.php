<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Devices\IntegrationKey;
use App\Domain\Settings\SettingKey;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Unit;
use Illuminate\Support\Facades\Http;
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

    // Midtrans terkonfigurasi supaya cancelPayment bisa menanyakan status QRIS
    // ke gateway (lihat perbaikan orphan-payment di bawah).
    Integration::query()->where('key', IntegrationKey::Midtrans)->delete();
    Integration::factory()->create([
        'key' => IntegrationKey::Midtrans,
        'base_url' => 'https://api.sandbox.midtrans.com',
        'token' => 'SB-Mid-server-uji',
        'is_active' => true,
    ]);
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
 * QRIS yang gateway PASTIKAN belum dibayar menandainya Expired dan kembali ke
 * tab isi saldo.
 */
test('cancelling a pending payment the gateway confirms unpaid expires it', function () {
    $payment = Payment::create([
        'customer_id' => $this->customer->id,
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Pending,
        'amount' => 50_000,
        'reference' => 'ORDER-EXP-1',
    ]);

    // Gateway memastikan masih menggantung (belum dibayar) → aman dihanguskan.
    Http::fake(['api.sandbox.midtrans.com/v2/*/status' => Http::response([
        'transaction_status' => 'pending',
        'gross_amount' => '50000.00',
    ])]);

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('paymentId', $payment->id)
        ->assertSee('Bayar dengan QRIS')
        ->call('cancelPayment')
        ->assertDontSee('Bayar dengan QRIS');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Expired);
});

/**
 * KRITIS (uang): pelanggan bisa menekan Batalkan dalam ~10 dtk setelah membayar,
 * sebelum poll menandai QRIS-nya Lunas. Batalkan TIDAK boleh menghanguskan yang
 * sudah dibayar — kalau tidak, uangnya masuk ke merchant tapi saldo tak pernah
 * bertambah, tanpa pemulihan otomatis. Gateway ditanya dulu; kalau sudah dibayar,
 * saldo dikreditkan, bukan dihanguskan.
 */
test('cancelling a QRIS the gateway already settled credits the wallet, never expires it', function () {
    $payment = Payment::create([
        'customer_id' => $this->customer->id,
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Pending,
        'amount' => 50_000,
        'reference' => 'ORDER-PAID-1',
    ]);

    // Gateway: ternyata SUDAH lunas (settlement).
    Http::fake(['api.sandbox.midtrans.com/v2/*/status' => Http::response([
        'transaction_status' => 'settlement',
        'gross_amount' => '50000.00',
    ])]);

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('paymentId', $payment->id)
        ->call('cancelPayment');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($this->customer->fresh()->balance)->toBe(50_000)
        ->and($this->customer->walletTransactions()->where('payment_id', $payment->id)->count())->toBe(1);
});

/**
 * Kalau gateway tak terjangkau saat Batalkan, kita TIDAK tahu apakah sudah
 * dibayar — jadi jangan hanguskan (biarkan Pending; poll & reconcile
 * menuntaskannya). Menghanguskan di sini berisiko menghilangkan uang.
 */
test('cancelling a QRIS is refused when the gateway is unreachable, payment stays pending', function () {
    $payment = Payment::create([
        'customer_id' => $this->customer->id,
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Pending,
        'amount' => 50_000,
        'reference' => 'ORDER-DOWN-1',
    ]);

    // Gateway down → statusOf null.
    Http::fake(['api.sandbox.midtrans.com/*' => Http::response('', 500)]);

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('paymentId', $payment->id)
        // Tetap di layar QRIS (tidak dihanguskan) — uang aman.
        ->call('cancelPayment')
        ->assertSee('Bayar dengan QRIS');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

/**
 * REGRESI (uang): dengan biaya admin, `amount` adalah TOTAL yang dibayar —
 * bukan yang masuk ke saldo. Layar sukses sempat melaporkan total kotor, jadi
 * pelanggan diberi tahu saldonya bertambah lebih banyak daripada kenyataannya.
 */
test('the success screen reports what actually landed in the balance, not the gross paid', function () {
    $payment = Payment::create([
        'customer_id' => $this->customer->id,
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Paid,
        'amount' => 52_500,   // 50.000 isi saldo + 2.500 biaya admin
        'fee' => 2_500,
        'verified_at' => now(),
    ]);

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('paymentId', $payment->id)
        ->assertSee('Pembayaran berhasil')
        ->assertSee('Rp 50.000')        // yang benar-benar masuk
        ->assertDontSee('Rp 52.500');   // bukan total kotor
});

/** Nominal bayar yang lebih besar dari pilihan harus dijelaskan, bukan dibiarkan asing. */
test('a pending payment with a fee explains the admin charge', function () {
    $payment = Payment::create([
        'customer_id' => $this->customer->id,
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Pending,
        'amount' => 52_500,
        'fee' => 2_500,
        'reference' => 'ORDER-FEE-1',
    ]);

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('paymentId', $payment->id)
        ->assertSee('Rp 52.500')
        ->assertSee('Termasuk biaya admin');
});
