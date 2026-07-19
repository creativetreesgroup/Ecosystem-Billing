<?php

namespace App\Filament\Resources\Units\Schemas;

use App\Domain\Devices\Capability;
use App\Domain\Devices\ControlDriver;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('outlet_id')
                    ->label('Outlet')
                    ->relationship('outlet', 'name')
                    ->required(),
                Select::make('unit_type_id')
                    ->label('Tipe unit')
                    ->relationship('unitType', 'name')
                    ->required(),
                TextInput::make('code')
                    ->label('Kode unit')
                    ->placeholder('mis. PS-01')
                    ->required(),
                Select::make('control_driver')
                    ->label('Driver kontrol TV')
                    ->options(ControlDriver::class)
                    ->native(false)
                    ->required()
                    ->live(),
                TextInput::make('control_ref')
                    ->label('Referensi kontrol')
                    ->helperText('Entity ID Home Assistant, atau topic Tasmota. Kosongkan untuk driver manual.')
                    ->visible(fn ($get) => $get('control_driver') !== ControlDriver::Manual->value),
                TextInput::make('tv_mac')
                    ->label('MAC address TV')
                    ->helperText('Untuk Wake-on-LAN (opsional).'),
                Select::make('capabilities')
                    ->label('Kapabilitas')
                    ->multiple()
                    ->options(Capability::class)
                    ->native(false),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->required(),
                Textarea::make('notes')
                    ->label('Catatan')
                    ->columnSpanFull(),
            ]);
    }
}
