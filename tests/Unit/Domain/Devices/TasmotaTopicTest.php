<?php

use App\Domain\Devices\TasmotaTopic;

test('command builds the cmnd power topic', function () {
    expect(TasmotaTopic::command('plug-ps01'))->toBe('cmnd/plug-ps01/POWER');
});

test('power builds the stat power topic', function () {
    expect(TasmotaTopic::power('plug-ps01'))->toBe('stat/plug-ps01/POWER');
});

test('availability builds the tele lwt topic', function () {
    expect(TasmotaTopic::availability('plug-ps01'))->toBe('tele/plug-ps01/LWT');
});

test('controlRefFrom extracts the control ref from a stat or tele topic', function (string $topic, ?string $expected) {
    expect(TasmotaTopic::controlRefFrom($topic))->toBe($expected);
})->with([
    ['stat/plug-ps01/POWER', 'plug-ps01'],
    ['tele/plug-ps01/LWT', 'plug-ps01'],
    ['cmnd/plug-ps01/POWER', 'plug-ps01'],
    ['garbage', null],
    ['', null],
]);
