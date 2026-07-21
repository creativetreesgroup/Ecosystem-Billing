<?php

namespace App\Filament\Resources\Customers\Actions;

use App\Domain\Billing\Rupiah;
use App\Domain\Discounts\Exceptions\DiscountNotApplicableException;
use App\Domain\Wallet\Actions\SettleCashTopUpAction;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;

/**
 * Isi saldo TUNAI oleh kasir: uang diterima di meja, saldo member bertambah.
 *
 * Ini satu-satunya cara saldo bertambah dari panel — dan sengaja tercatat
 * sebagai pembayaran tunai yang diverifikasi kasir, bukan angka yang muncul
 * begitu saja: laci kas harus bisa dicocokkan dengan barisnya. Perubahan
 * saldonya lewat Wallet (satu-satunya tempat saldo boleh berubah), jadi buku
 * besar & kolom saldo tidak mungkin berselisih.
 */
class CashTopUpAction
{
    public static function make(): Action
    {
        return Action::make('cashTopUp')
            ->label('Isi Saldo Tunai')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalWidth(Width::Medium)
            ->modalAlignment(Alignment::Center)
            ->modalFooterActionsAlignment(Alignment::Center)
            ->modalIcon('heroicon-o-banknotes')
            ->modalIconColor('success')
            ->modalHeading(fn (Customer $record) => 'Isi saldo tunai — '.$record->name)
            ->modalDescription(fn (Customer $record) => 'Saldo sekarang '.Rupiah::format($record->balance))
            ->modalSubmitActionLabel('Terima & tambah saldo')
            // Hanya yang boleh mengubah data member (kasir & owner). Aksi kustom
            // TIDAK tergerbang otomatis oleh policy — tanpa ini siapa pun yang
            // bisa membuka daftar member bisa menambah saldo.
            ->authorize(fn (Customer $record) => Auth::user()?->can('update', $record))
            ->schema([
                // Tanpa maxValue: operator tepercaya boleh memasukkan nominal
                // berapa pun (uang tunai sungguhan). Batas atas hanya ada di
                // pengisian mandiri kios, bukan di sini.
                TextInput::make('amount')
                    ->hiddenLabel()
                    ->placeholder('Nominal tunai diterima')
                    ->prefix('Rp')
                    ->numeric()
                    ->minValue(1000)
                    ->required(),
                // Voucher opsional → bonus saldo (kode salah membatalkan aksi
                // dengan pesan, tak ada saldo terkredit setengah jalan).
                TextInput::make('voucher_code')
                    ->hiddenLabel()
                    ->placeholder('Kode voucher (opsional)')
                    ->extraInputAttributes(['style' => 'text-transform:uppercase']),
            ])
            ->action(function (array $data, Customer $record): void {
                try {
                    $transaction = app(SettleCashTopUpAction::class)->handle(
                        $record,
                        (int) $data['amount'],
                        Auth::user(),
                        $data['voucher_code'] ?: null,
                    );
                } catch (DiscountNotApplicableException $e) {
                    Notification::make()->title('Voucher tidak dipakai')->body($e->getMessage())->warning()->send();

                    return;
                }

                Notification::make()
                    ->title('Saldo bertambah')
                    ->body('Saldo '.$record->name.' kini '.Rupiah::format($transaction->balance_after).'.')
                    ->success()
                    ->send();
            });
    }
}
