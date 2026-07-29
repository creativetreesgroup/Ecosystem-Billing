<?php

namespace App\Filament\Resources\Customers\Actions;

use App\Domain\Billing\Rupiah;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Koreksi saldo MANUAL oleh owner — beda dari Isi Saldo Tunai.
 *
 * Isi Saldo Tunai mencatat uang yang benar-benar diterima (pendapatan). Koreksi
 * ini TIDAK: ia menambah atau MENGURANGI saldo untuk membetulkan selisih atau
 * membebankan utang/denda, dan boleh menjadikan saldo minus dengan sengaja.
 * Karena itu owner-only, wajib beralasan (jejaknya tercatat di buku besar), dan
 * hasil akhirnya ditampilkan langsung sebelum disimpan supaya tidak salah arah.
 */
class AdjustBalanceAction
{
    public static function make(): Action
    {
        return Action::make('adjustBalance')
            ->label('Koreksi Saldo')
            ->icon('heroicon-o-scale')
            ->color('warning')
            ->modalWidth(Width::Medium)
            ->modalAlignment(Alignment::Center)
            ->modalFooterActionsAlignment(Alignment::Center)
            ->modalIcon('heroicon-o-scale')
            ->modalIconColor('warning')
            ->modalHeading(fn (Customer $record) => 'Koreksi saldo — '.$record->name)
            ->modalDescription(fn (Customer $record) => 'Saldo sekarang '.Rupiah::format($record->balance))
            ->modalSubmitActionLabel('Simpan koreksi')
            // Wewenang owner: koreksi bebas (termasuk membuat saldo minus) tidak
            // boleh ada di tangan kasir. Aksi kustom tidak tergerbang policy
            // otomatis, jadi gerbang ini wajib eksplisit.
            ->authorize(fn (Customer $record) => Auth::user()?->can('adjustBalance', $record))
            ->schema([
                ToggleButtons::make('direction')
                    ->hiddenLabel()
                    ->options(['add' => 'Tambah', 'subtract' => 'Kurangi'])
                    ->icons(['add' => 'heroicon-o-plus', 'subtract' => 'heroicon-o-minus'])
                    ->colors(['add' => 'success', 'subtract' => 'danger'])
                    ->default('add')
                    ->inline()
                    ->grouped()
                    ->extraAttributes(['style' => 'width: 100%; grid-auto-columns: 1fr;'])
                    ->live()
                    ->required(),
                // Tanpa maxValue: koreksi owner sengaja tak berbatas.
                TextInput::make('amount')
                    ->hiddenLabel()
                    ->placeholder('Nominal koreksi')
                    ->prefix('Rp')
                    ->numeric()
                    ->minValue(1)
                    ->live(debounce: 300)
                    ->required(),
                // Hasil akhir tampil langsung: arah × nominal terhadap saldo
                // sekarang. Inilah yang membuat "kurangi 5jt dari 1jt → −4jt"
                // tidak pernah salah tafsir.
                Placeholder::make('preview')
                    ->hiddenLabel()
                    ->content(function (Get $get, Customer $record): HtmlString {
                        $after = $record->balance + self::signedAmount($get('direction'), (int) $get('amount'));
                        $color = $after < 0 ? '#dc2626' : '#16a34a';

                        return new HtmlString(
                            '<div style="text-align:center">Saldo akan menjadi<br>'
                            .'<span style="font-size:1.5rem;font-weight:800;color:'.$color.'">'
                            .Rupiah::format($after).'</span></div>'
                        );
                    }),
                TextInput::make('reason')
                    ->label('Alasan koreksi')
                    ->placeholder('mis. kompensasi TV mati, pembulatan, denda')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data, Customer $record): void {
                $signed = self::signedAmount($data['direction'], (int) $data['amount']);

                app(Wallet::class)->adjust(
                    $record,
                    $signed,
                    $data['reason'],
                    Auth::user(),
                    allowNegative: true,
                );

                Notification::make()
                    ->title('Saldo dikoreksi')
                    ->body('Saldo '.$record->name.' kini '.Rupiah::format($record->fresh()->balance).'.')
                    ->success()
                    ->send();
            });
    }

    private static function signedAmount(?string $direction, int $amount): int
    {
        return $direction === 'subtract' ? -$amount : $amount;
    }
}
