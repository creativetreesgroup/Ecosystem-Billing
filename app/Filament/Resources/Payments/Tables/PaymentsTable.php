<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Domain\Billing\Actions\RejectPaymentAction;
use App\Domain\Billing\Actions\VerifyPaymentAction;
use App\Domain\Billing\Exceptions\IllegalPaymentTransitionException;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\Rupiah;
use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn () => Payment::query()->with(['rentalSession.unit', 'verifiedBy']))
            // Yang menunggu diperiksa lebih dulu: layar ini dibuka untuk
            // MENGERJAKAN antreannya, bukan membaca arsip pembayaran.
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(PaymentStatus::class)
                    ->default(PaymentStatus::AwaitingVerification->value),
            ])
            ->columns([
                TextColumn::make('rentalSession.unit.code')
                    ->label('Unit')
                    ->weight(FontWeight::Bold)
                    ->searchable(),
                TextColumn::make('rentalSession.customer_name')
                    ->label('Pelanggan')
                    ->placeholder('Tanpa nama')
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('Nominal')
                    ->formatStateUsing(fn (int $state): string => Rupiah::format($state))
                    ->weight(FontWeight::Bold)
                    ->sortable(),
                TextColumn::make('method')
                    ->label('Metode')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Diterima')
                    ->visibleFrom('lg')
                    ->since(),
                TextColumn::make('verifiedBy.name')
                    ->label('Diperiksa oleh')
                    ->visibleFrom('xl')
                    ->placeholder('-'),
            ])
            ->recordActions([
                self::reviewAction(),
                self::rejectAction(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Bukti WAJIB dilihat sebelum diterima.
     *
     * Karena itu tombolnya membuka gambarnya, bukan langsung menyetujui: kalau
     * "Terima" bisa ditekan dari daftar tanpa melihat apa pun, verifikasi
     * hanyalah formalitas yang menandatangani uang yang belum pernah diperiksa.
     */
    private static function reviewAction(): Action
    {
        return Action::make('review')
            ->label('Periksa bukti')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->color('success')
            ->authorize(fn (Payment $record): bool => Auth::user()->can('verify', $record))
            ->modalWidth(Width::Large)
            ->modalAlignment(Alignment::Center)
            ->modalFooterActionsAlignment(Alignment::Center)
            ->modalIcon(Heroicon::OutlinedBanknotes)
            ->modalIconColor('success')
            ->modalHeading(fn (Payment $record): string => 'Bukti transfer — '.$record->rentalSession->unit->code)
            ->modalDescription('Cocokkan dengan mutasi rekening sebelum menerima. Setelah diterima, angkanya langsung masuk laporan pendapatan.')
            ->modalSubmitActionLabel('Ya, uangnya sudah masuk')
            ->schema([
                TextEntry::make('nominal')
                    ->hiddenLabel()
                    ->alignCenter()
                    ->state(fn (Payment $record): string => Rupiah::format($record->amount))
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold)
                    ->color('success'),
                TextEntry::make('konteks')
                    ->hiddenLabel()
                    ->alignCenter()
                    ->state(fn (Payment $record): string => ($record->rentalSession->customer_name ?: 'Tanpa nama')
                        .' · '.$record->rentalSession->unit->code
                        .' · '.$record->created_at->setTimezone(config('app.display_timezone'))->format('d M Y H:i'))
                    ->size(TextSize::Small)
                    ->color('gray'),
                ImageEntry::make('proof_path')
                    ->hiddenLabel()
                    ->alignCenter()
                    ->disk('local')
                    ->height(320)
                    ->placeholder('Pelanggan belum mengunggah bukti.'),
            ])
            ->action(function (Payment $record): void {
                try {
                    $verified = app(VerifyPaymentAction::class)->handle($record, Auth::user());
                } catch (IllegalPaymentTransitionException $exception) {
                    // Dua kasir membuka daftar yang sama lalu menekan Terima
                    // pada baris yang sama. Tanpa tangkapan ini yang muncul
                    // halaman error 500 di tengah antrean pelanggan.
                    Notification::make()
                        ->title('Tidak jadi diverifikasi')
                        ->body($exception->getMessage())
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(Rupiah::format($verified->amount).' masuk laporan')
                    ->body('Tercatat atas nama '.Auth::user()->name.'. Angka ini yang dicocokkan saat menutup kas.')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Tolak')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize(fn (Payment $record): bool => Auth::user()->can('reject', $record))
            ->modalWidth(Width::Medium)
            ->modalAlignment(Alignment::Center)
            ->modalFooterActionsAlignment(Alignment::Center)
            ->modalIcon(Heroicon::OutlinedXCircle)
            ->modalIconColor('danger')
            ->modalHeading('Tolak bukti pembayaran')
            ->modalDescription('Sesinya TIDAK ikut dibatalkan — pelanggan masih berutang, dan penagihannya diselesaikan dengan cara lain.')
            ->modalSubmitActionLabel('Tolak bukti ini')
            ->schema([
                // Alasannya wajib: pelanggan berhak tahu kenapa buktinya
                // ditolak, dan "ditolak" tanpa keterangan tidak bisa
                // dipertanggungjawabkan siapa pun saat ada sengketa.
                Textarea::make('reason')
                    ->label('Alasan penolakan')
                    ->placeholder('mis. Nominal transfer Rp 50.000, seharusnya Rp 75.000.')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (Payment $record, array $data): void {
                try {
                    app(RejectPaymentAction::class)->handle($record, Auth::user(), $data['reason']);
                } catch (IllegalPaymentTransitionException $exception) {
                    Notification::make()
                        ->title('Tidak jadi ditolak')
                        ->body($exception->getMessage())
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Bukti ditolak')
                    ->body('Alasan tercatat atas nama '.Auth::user()->name.'. Pelanggan masih berutang '.Rupiah::format($record->amount).'.')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->warning()
                    ->persistent()
                    ->send();
            });
    }
}
