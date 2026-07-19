<?php

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Filters\SelectFilter;

/**
 * Panel dipakai dari HP. <select> asli browser dirender oleh sistem operasi
 * DI LUAR kotak modal, sehingga muncul sebagai panel melayang di pojok layar
 * yang lepas dari form pemicunya.
 *
 * Default-nya dipasang sekali di AppServiceServiceProvider; test ini yang
 * menjaganya, karena kalau lepas gejalanya cuma terlihat di layar kecil dan
 * bisa lolos berbulan-bulan tanpa ketahuan dari desktop.
 */
test('dropdowns never fall back to the browser native control', function () {
    expect(Select::make('example')->isNative())->toBeFalse()
        ->and(DatePicker::make('example')->isNative())->toBeFalse()
        ->and(TimePicker::make('example')->isNative())->toBeFalse()
        ->and(DateTimePicker::make('example')->isNative())->toBeFalse()
        ->and(SelectFilter::make('example')->isNative())->toBeFalse()
        ->and(SelectColumn::make('example')->isNative())->toBeFalse();
});
