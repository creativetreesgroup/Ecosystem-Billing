<?php

use App\Domain\Devices\ControlDriver;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

/**
 * Pelanggan yang sedang bermain tetap butuh isi saldo dan pesan makanan.
 *
 * Sebelumnya sesi aktif MENGGANTIKAN seluruh dasbor: yang tersisa di layar
 * hanya kartu "Sedang main" dengan satu tombol "Berhenti & bayar". Untuk jajan,
 * pelanggan harus menghentikan sesinya dulu — menghentikan tagihan yang sedang
 * berjalan, lalu memulainya lagi dari awal. Merugikan pelanggan (repot) dan
 * outlet (pesanan yang batal karena malas).
 */
beforeEach(function () {
    User::factory()->owner()->create();

    $this->unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $this->package = Package::factory()->for($this->unit->unitType)->create([
        'price' => 10_000,
        'duration_minutes' => 60,
        'is_active' => true,
    ]);
    $this->customer = Customer::factory()->create(['balance' => 50_000]);
});

test('a customer who is playing can still reach top up and food ordering', function () {
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->call('play')
        ->assertHasNoErrors();

    expect($this->unit->fresh()->activeSession)->not->toBeNull();

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        // Kartu sesi tetap ada — ia berdiri DI ATAS dasbor, bukan menggantikannya.
        ->assertSee('Sedang main')
        // Dan dasbornya tetap terjangkau.
        ->assertSee('Isi saldo')
        ->assertSee('Pesan');
});

test('the play tile is switched off while a session is running', function () {
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->call('play');

    $html = Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->html();

    // Memulai sesi kedua di unit yang sama tidak masuk akal, jadi tile "Main"
    // dimatikan — tetapi HANYA itu. Mematikan "Pesan" sekalian adalah persis
    // masalah yang sedang diperbaiki.
    expect($html)->toContain('aria-label="Main"')
        ->and($html)->toMatch('/disabled[^>]*aria-label="Main"|aria-label="Main"[^>]*disabled/s');
});

test('opening the order tab does not disturb the running session', function () {
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->call('play');

    $session = $this->unit->fresh()->activeSession;

    // Membuka tab pesanan tidak boleh menyentuh sesi maupun saldo — jalur uang
    // hanya bergerak saat pesanan benar-benar dibayar.
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('tab', 'order')
        ->assertHasNoErrors();

    $after = $this->unit->fresh()->activeSession;

    expect($after)->not->toBeNull()
        ->and($after->id)->toBe($session->id)
        ->and($after->status)->toBe($session->status)
        ->and($this->customer->fresh()->balance)->toBe(40_000);
});

test('a session belonging to someone else still hides the dashboard', function () {
    $other = Customer::factory()->create(['balance' => 50_000]);

    Livewire::actingAs($other, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->call('play');

    // Batasnya tetap tegas: unit yang sedang dipakai orang lain tidak boleh
    // menampilkan dasbor siapa pun — bukan sekadar soal tampilan, itu pintu
    // masuk ke saldo dan pesanan milik orang lain.
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->assertSee('Unit sedang dipakai')
        ->assertDontSee('aria-label="Isi saldo"', escape: false);
});

test('the quick tiles sit inside the session card, not further down the page', function () {
    // Open Play, bukan paket: hanya kartu Open Play yang punya tombol berhenti,
    // dan tombol itulah patokan bawah yang dipakai test ini.
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->call('startOpenPlay');

    $html = Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->html();

    // Barisnya harus berada SETELAH waktu berjalan dan SEBELUM tombol berhenti.
    // Menaruhnya jauh di bawah halaman sama saja menyembunyikannya: mata
    // pelanggan sedang berada di kartu sesi, bukan di ujung layar.
    $tiles = strpos($html, 'quick-inline');
    // Atribut tombolnya, bukan sekadar kata "stopOpenPlay": kata itu juga
    // muncul lebih dulu di x-init sebagai penjaga plafon saldo.
    $stop = strpos($html, 'wire:click="stopOpenPlay"');

    expect($tiles)->not->toBeFalse()
        ->and($stop)->not->toBeFalse()
        ->and($tiles)->toBeLessThan($stop);
});

test('the dashboard becomes a slide-up sheet while playing', function () {
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->call('play');

    $playingHtml = Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->html();

    // Kelas dasarnya statis; Alpine hanya menambahkan is-open saat dibuka.
    // Menegaskan perilakunya, bukan bentuk ekspresi Alpine-nya.
    expect($playingHtml)->toContain('class="sheet"')
        ->and($playingHtml)->toContain("'is-open'")
        ->and($playingHtml)->toContain('kiosk-sheet');
});

test('outside a session the dashboard stays a plain page, not a sheet', function () {
    // Pembungkusnya tidak boleh berkelas apa pun di luar sesi -- kalau tidak,
    // dasbor biasa ikut tersembunyi di balik panel yang tak pernah dibuka.
    $html = Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->html();

    expect($html)->not->toContain('sheet is-open')
        ->and($html)->not->toContain('kiosk-sheet');
});

test('the slide-over panel is hidden from the very first paint', function () {
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->call('play');

    $html = Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->html();

    // Kelasnya harus ada di HTML, bukan ditempelkan Alpine setelahnya. Tanpa
    // ini seluruh dasbor terender penuh di bawah kartu sesi lebih dulu, lalu
    // mengejut hilang begitu Alpine hidup -- halaman yang melompat di setiap
    // muat, dan paling terasa justru di HP kelas bawah yang dipakai pelanggan.
    expect($html)->toContain('class="sheet"')
        ->and($html)->not->toContain("'sheet is-open' : 'sheet'");
});

test('while playing only the session card is on screen, the balance card moves into the panel', function () {
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->call('play');

    $html = Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->html();

    $sheetAt = strpos($html, 'class="sheet"');
    $balanceAt = strpos($html, 'balance-card');

    expect($sheetAt)->not->toBeFalse('Panel geser tidak dirender saat bermain.')
        ->and($balanceAt)->not->toBeFalse('Kartu saldo hilang sama sekali dari dasbor.');

    // Kartu saldo harus berada SESUDAH pembuka panel, artinya di dalamnya.
    // Ketika ia berdiri di luar, layar menampilkan dua kartu bertumpuk dengan
    // dua angka saldo yang berbeda beberapa rupiah — satu berjalan per detik,
    // satu diam — dan pelanggan tidak tahu mana yang benar.
    expect($balanceAt)->toBeGreaterThan($sheetAt);
});
