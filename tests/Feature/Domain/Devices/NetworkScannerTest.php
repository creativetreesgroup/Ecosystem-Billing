<?php

use App\Domain\Devices\DiscoveredDevice;
use App\Domain\Devices\NetworkScanner;

test('it reads the headers of an ssdp reply', function () {
    $raw = "HTTP/1.1 200 OK\r\n"
        ."CACHE-CONTROL: max-age=1800\r\n"
        ."LOCATION: http://192.168.100.7:8008/ssdp/device-desc.xml\r\n"
        ."SERVER: Linux/4.14.198+, UPnP/1.0, Chromecast/1.6.18\r\n"
        ."ST: urn:dial-multiscreen-org:service:dial:1\r\n\r\n";

    expect(NetworkScanner::parseHeaders($raw))
        ->toMatchArray([
            'location' => 'http://192.168.100.7:8008/ssdp/device-desc.xml',
            'server' => 'Linux/4.14.198+, UPnP/1.0, Chromecast/1.6.18',
        ]);
});

/**
 * Nilai LOCATION mengandung "http://…" — memotong di titik dua PERTAMA akan
 * memenggalnya jadi "http". Baris tanpa titik dua sama sekali juga harus
 * dilewati, bukan bikin error.
 */
test('it keeps colons inside a header value', function () {
    $headers = NetworkScanner::parseHeaders("HTTP/1.1 200 OK\r\nLOCATION: http://10.0.0.5:2869/desc.xml\r\n");

    expect($headers['location'])->toBe('http://10.0.0.5:2869/desc.xml');
});

/**
 * Deskripsi UPnP dari perangkat murah sering cacat — namespace ganda, tag
 * tidak ditutup — sehingga parser XML sungguhan gagal total. Padahal yang
 * dibutuhkan cuma tiga nilai.
 */
test('it reads a device description even when the xml is malformed', function () {
    $xml = <<<'XML'
    <?xml version="1.0"?>
    <root xmlns="urn:schemas-upnp-org:device-1-0"><device>
    <deviceType>urn:schemas-upnp-org:device:MediaRenderer:1</deviceType>
    <friendlyName>TCL Smart TV</friendlyName>
    <manufacturer>TCL</manufacturer>
    <modelName>65C755</modelName>
    <unclosed>
    XML;

    expect(NetworkScanner::parseDescription($xml))
        ->toBe(['TCL Smart TV', '65C755', 'TCL']);
});

test('it returns nulls when a description has nothing useful', function () {
    expect(NetworkScanner::parseDescription('<root></root>'))->toBe([null, null, null]);
});

/**
 * Router pun menjawab SSDP — di jaringan uji nyata ia muncul sebagai
 * "Portable SDK for UPnP devices". Tanpa saringan ini daftar TV-nya
 * menyesatkan operator.
 */
test('it tells televisions apart from other upnp devices', function (string $server, ?string $name, bool $expected) {
    $device = new DiscoveredDevice(ip: '192.168.100.7', name: $name, server: $server);

    expect($device->looksLikeTelevision())->toBe($expected);
})->with([
    'chromecast / android tv' => ['Linux/4.14.198+, UPnP/1.0, Chromecast/1.6.18', null, true],
    'dlna renderer' => ['UPnP/1.0, DLNADOC/1.50 Platinum/1.0.5.13', null, true],
    'merek lokal' => ['Linux/3.10 UPnP/1.0', 'TCL Smart TV', true],
    'router' => ['Linux/5.10.0, UPnP/1.0, Portable SDK for UPnP devices/1.14.12', null, false],
    'printer' => ['HP Linux/2.6 UPnP/1.0', 'HP LaserJet', false],
]);

test('it falls back through name, model and ip when labelling a device', function () {
    expect((new DiscoveredDevice(ip: '10.0.0.5', name: 'TV Ruang 1'))->label())->toBe('TV Ruang 1 — 10.0.0.5')
        ->and((new DiscoveredDevice(ip: '10.0.0.5', model: '65C755'))->label())->toBe('65C755 — 10.0.0.5')
        ->and((new DiscoveredDevice(ip: '10.0.0.5'))->label())->toBe('10.0.0.5 — 10.0.0.5');
});

/**
 * `arp` di macOS membuang nol di depan tiap oktet — router di jaringan uji
 * terbaca "80:60:36:69:2f:6". WakeOnLan::send() menuntut TEPAT 12 digit heksa
 * dan menolak bentuk pendek itu dalam diam: TV tidak pernah bangun, tanpa
 * pesan apa pun yang menjelaskan kenapa.
 */
test('it pads a mac address that lost its leading zeros', function () {
    expect(NetworkScanner::normaliseMac('80:60:36:69:2f:6'))->toBe('80:60:36:69:2f:06')
        ->and(NetworkScanner::normaliseMac('D8:14:DF:7F:7D:47'))->toBe('d8:14:df:7f:7d:47')
        ->and(NetworkScanner::normaliseMac('d8-14-df-7f-7d-47'))->toBe('d8:14:df:7f:7d:47');
});

test('it refuses a mac address that is not one', function (string $raw) {
    expect(NetworkScanner::normaliseMac($raw))->toBeNull();
})->with([
    'kurang oktet' => ['d8:14:df:7f:7d'],
    'kelebihan oktet' => ['d8:14:df:7f:7d:47:99'],
    'bukan heksa' => ['zz:14:df:7f:7d:47'],
    'oktet kepanjangan' => ['d81:14:df:7f:7d:47'],
    'kosong' => [''],
]);

/**
 * Bukti bahwa normalisasi ini memang yang membuat WoL berhasil: bentuk pendek
 * ditolak WakeOnLan, bentuk hasil normalisasi diterima.
 */
test('a normalised mac is one wake-on-lan actually accepts', function () {
    $short = '80:60:36:69:2f:6';

    expect(strlen(preg_replace('/[^0-9a-f]/i', '', $short)))->toBe(11)
        ->and(strlen(preg_replace('/[^0-9a-f]/i', '', NetworkScanner::normaliseMac($short))))->toBe(12);
});
