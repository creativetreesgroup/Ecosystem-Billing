<?php

use App\Domain\Devices\ControlDriver;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

/**
 * Markup kios harus seimbang di SETIAP keadaan, bukan hanya keadaan awal.
 *
 * Pernah terjadi: penutup </div> pembungkus panel geser tersesat ke dalam
 * cabang @elseif ($confirm === 'topup'), sehingga hanya terender saat modal
 * top-up kebetulan terbuka. Di keadaan lain root komponen tidak pernah ditutup.
 *
 * Akibatnya berantai dan menyesatkan: Livewire menolak memasang komponennya
 * ("missing closing tags found"), lalu setiap wire:click sesudahnya gagal
 * dengan "Cannot read properties of undefined (reading 'uri')". Yang dilihat
 * pelanggan bukan pesan error — tombolnya tampak normal, ditekan, dan tidak
 * terjadi apa-apa. Di outlet itu berarti pelanggan berdiri di depan TV menekan
 * "Mulai main" berulang kali tanpa tahu apa yang salah.
 *
 * Tujuh test kios yang ada semuanya lolos, karena assertSee() dan
 * assertHasNoErrors() tidak peduli pada tag yang tidak tertutup.
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

/**
 * Tag yang dibuka tetapi tidak pernah ditutup, dan penutup tanpa pembuka.
 * Larik kosong berarti markup-nya sehat.
 *
 * @return array<int, string>
 */
function kioskMarkupProblems(string $html): array
{
    // Komentar dibuang lebih dulu: Livewire menaburkan penanda kondisional
    // <!--[if BLOCK]><![endif]--> yang bukan bagian dari struktur dokumen.
    $html = (string) preg_replace('/<!--.*?-->/s', '', $html);

    $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta',
        'source', 'track', 'wbr', 'path', 'circle', 'rect', 'line', 'polyline',
        'polygon', 'ellipse', 'stop', 'use'];

    preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)\b([^>]*?)(\/?)>/', $html, $matches, PREG_SET_ORDER);

    $stack = [];
    $problems = [];

    foreach ($matches as $match) {
        [, $closing, $name, , $selfClosing] = $match + [4 => ''];
        $name = strtolower($name);

        if (in_array($name, $void, true) || $selfClosing === '/') {
            continue;
        }

        if ($closing === '/') {
            if ($stack !== [] && end($stack) === $name) {
                array_pop($stack);
            } else {
                $problems[] = "penutup </{$name}> tanpa pembuka";
            }

            continue;
        }

        $stack[] = $name;
    }

    foreach ($stack as $unclosed) {
        $problems[] = "<{$unclosed}> tidak pernah ditutup";
    }

    return $problems;
}

test('the kiosk markup stays balanced in every state a customer can reach', function () {
    $states = [
        'tamu belum masuk' => fn (): string => Livewire::test('kiosk.unit-kiosk', ['unit' => $this->unit])->html(),
        'dasbor' => fn (): string => Livewire::actingAs($this->customer, 'customer')
            ->test('kiosk.unit-kiosk', ['unit' => $this->unit])->html(),
        'tab pesan' => fn (): string => Livewire::actingAs($this->customer, 'customer')
            ->test('kiosk.unit-kiosk', ['unit' => $this->unit])->set('tab', 'order')->html(),
        'tab isi saldo' => fn (): string => Livewire::actingAs($this->customer, 'customer')
            ->test('kiosk.unit-kiosk', ['unit' => $this->unit])->set('tab', 'topup')->html(),
        'tab riwayat' => fn (): string => Livewire::actingAs($this->customer, 'customer')
            ->test('kiosk.unit-kiosk', ['unit' => $this->unit])->set('tab', 'history')->html(),
        'konfirmasi open play' => fn (): string => Livewire::actingAs($this->customer, 'customer')
            ->test('kiosk.unit-kiosk', ['unit' => $this->unit])->set('playChoice', 'open')->call('askPlay')->html(),
        'konfirmasi paket' => fn (): string => Livewire::actingAs($this->customer, 'customer')
            ->test('kiosk.unit-kiosk', ['unit' => $this->unit])->set('playChoice', (string) $this->package->id)->call('askPlay')->html(),
    ];

    foreach ($states as $label => $render) {
        expect(kioskMarkupProblems($render()))->toBe([], "Markup rusak pada keadaan: {$label}");
    }
});

test('the kiosk markup stays balanced while a session is running', function () {
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->call('play');

    $playing = Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->html();

    $playingOrder = Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('tab', 'order')
        ->html();

    expect(kioskMarkupProblems($playing))->toBe([], 'Markup rusak saat sedang main')
        ->and(kioskMarkupProblems($playingOrder))->toBe([], 'Markup rusak saat memesan sambil main');
});

test('the kiosk markup stays balanced when the unit belongs to someone else', function () {
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->call('play');

    $other = Customer::factory()->create(['balance' => 1_000]);

    $html = Livewire::actingAs($other, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->html();

    expect(kioskMarkupProblems($html))->toBe([]);
});

/**
 * Leluhur sebuah elemen, dari terluar ke terdalam, berupa daftar nilai
 * atribut class-nya.
 *
 * @return array<int, string>
 */
function kioskAncestorClassesOf(string $html, string $needleClass): array
{
    $html = (string) preg_replace('/<!--.*?-->/s', '', $html);

    $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta',
        'source', 'track', 'wbr', 'path', 'circle', 'rect', 'line', 'polyline',
        'polygon', 'ellipse', 'stop', 'use'];

    preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)\b([^>]*?)(\/?)>/', $html, $matches, PREG_SET_ORDER);

    $stack = [];

    foreach ($matches as $match) {
        [, $closing, $name, $attrs, $selfClosing] = $match + [4 => ''];
        $name = strtolower($name);

        if (in_array($name, $void, true) || $selfClosing === '/') {
            continue;
        }

        if ($closing === '/') {
            array_pop($stack);

            continue;
        }

        preg_match('/class="([^"]*)"/', $attrs, $classMatch);
        $class = $classMatch[1] ?? '';

        if (str_contains($class, $needleClass)) {
            return $stack;
        }

        $stack[] = $class;
    }

    return [];
}

test('a payment confirmation is never trapped inside the collapsible panel', function () {
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->call('play');

    // Konfirmasi dibuka SAAT bermain, yaitu saat panel dasbor bisa tertutup.
    $html = Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('tab', 'topup')
        ->set('topUpAmount', 200_000)
        ->set('method', 'transfer')
        ->call('askTopUp')
        ->html();

    expect($html)->toContain('modal-backdrop');

    $ancestors = kioskAncestorClassesOf($html, 'modal-backdrop');

    // Panel dasbor memakai visibility:hidden saat tertutup. Konfirmasi yang
    // bersarang di dalamnya akan ikut tak terlihat — pelanggan menekan
    // "Lanjut", saldonya tertahan menunggu keputusan, dan layar tidak
    // menampilkan apa pun untuk diputuskan.
    foreach ($ancestors as $class) {
        expect($class)->not->toContain('sheet');
    }
});
