<?php

use App\Domain\Devices\WakeOnLan;

test('it sends a well-formed magic packet to the given address and port', function () {
    $listener = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    expect($listener)->not->toBeFalse();

    $localName = stream_socket_get_name($listener, false);
    $port = (int) substr($localName, strrpos($localName, ':') + 1);

    $sent = WakeOnLan::send('AA:BB:CC:DD:EE:FF', '127.0.0.1', $port);
    expect($sent)->toBeTrue();

    $packet = stream_socket_recvfrom($listener, 1024);
    fclose($listener);

    expect(strlen($packet))->toBe(102);
    expect(substr($packet, 0, 6))->toBe(str_repeat("\xFF", 6));

    $macBytes = hex2bin('AABBCCDDEEFF');
    for ($i = 0; $i < 16; $i++) {
        expect(substr($packet, 6 + $i * 6, 6))->toBe($macBytes);
    }
});

test('it accepts mac addresses with dash or no separators', function (string $mac) {
    $listener = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    $localName = stream_socket_get_name($listener, false);
    $port = (int) substr($localName, strrpos($localName, ':') + 1);

    expect(WakeOnLan::send($mac, '127.0.0.1', $port))->toBeTrue();

    fclose($listener);
})->with([
    'AA-BB-CC-DD-EE-FF',
    'aabbccddeeff',
]);

test('it fails gracefully for a malformed mac address', function () {
    expect(WakeOnLan::send('not-a-mac', '127.0.0.1', 9))->toBeFalse();
});
