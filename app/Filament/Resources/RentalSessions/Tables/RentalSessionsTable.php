<?php

namespace App\Filament\Resources\RentalSessions\Tables;

use App\Domain\Billing\Rupiah;
use App\Domain\Sessions\Actions\VoidSessionAction;
use App\Domain\Sessions\SessionStatus;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RentalSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('unit.code')
                    ->label('Unit')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('openedBy.name')
                    ->visibleFrom('lg')
                    ->label('Kasir'),
                TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('type')
                    ->visibleFrom('md')
                    ->label('Tipe')
                    ->badge(),
                TextColumn::make('package.name')
                    ->visibleFrom('xl')
                    ->label('Paket')
                    ->placeholder('-'),
                TextColumn::make('started_at')
                    ->visibleFrom('lg')
                    ->label('Mulai')
                    ->dateTime('d/m/Y H:i', timezone: config('app.display_timezone'))
                    ->sortable(),
                TextColumn::make('ended_at')
                    ->visibleFrom('md')
                    ->label('Selesai')
                    ->dateTime('d/m/Y H:i', timezone: config('app.display_timezone'))
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : Rupiah::format($state))
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->visibleFrom('md')
                    ->label('Pembayaran')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('void_reason')
                    ->visibleFrom('xl')
                    ->label('Alasan void')
                    // Ditulis lewat RichEditor, jadi harus dirender sebagai
                    // HTML — kalau tidak, tag-nya terbaca mentah di tabel.
                    ->html()
                    ->wrap()
                    ->placeholder('-'),
                TextColumn::make('voidedBy.name')
                    ->visibleFrom('xl')
                    ->label('Di-void oleh')
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('unit_id')
                    ->label('Unit')
                    ->options(fn () => Unit::query()->pluck('code', 'id')),
                SelectFilter::make('opened_by')
                    ->label('Kasir')
                    ->options(fn () => User::query()->pluck('name', 'id')),
                Filter::make('started_at')
                    ->schema([
                        DatePicker::make('from')->label('Dari tanggal'),
                        DatePicker::make('until')->label('Sampai tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('started_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('started_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                Action::make('void')
                    ->label('Void')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->authorize(fn (RentalSession $record) => auth()->user()->can('void', $record))
                    ->visible(fn (RentalSession $record) => $record->status !== SessionStatus::Voided)
                    ->modalWidth(Width::Large)
                    ->schema([
                        RichEditor::make('reason')
                            ->label('Alasan void')
                            ->required()
                            // Toolbar dipangkas sampai muat SATU baris: tebal,
                            // miring, garis bawah untuk menandai nominal &
                            // nama, tautan untuk bukti (mis. chat/mutasi).
                            //
                            // Daftar, kutipan, heading, tabel, dan lampiran
                            // tidak masuk. Ini catatan audit satu paragraf,
                            // bukan artikel: tiap opsi gaya tambahan membuat
                            // isinya makin tidak seragam antar kasir, makin
                            // berantakan saat dibaca ulang di kolom tabel, dan
                            // memaksa toolbarnya melipat jadi dua baris di
                            // modal yang sempit.
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'link'],
                                ['undo', 'redo'],
                            ])
                            // required() SAJA tidak cukup: editor yang cuma
                            // di-klik lalu ditinggal mengirim "<p></p>" —
                            // secara teknis terisi, tapi kosong bagi manusia.
                            // Ini catatan audit pembatalan uang; kosong tidak
                            // boleh lolos.
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (self::plainText($value) === '') {
                                    $fail('Alasan void wajib diisi.');
                                }
                            }),
                    ])
                    ->action(function (RentalSession $record, array $data): void {
                        // Dibaca SEBELUM di-void: setelah statusnya berubah,
                        // "tadinya berjalan" tidak bisa diketahui lagi.
                        $wasActive = $record->status === SessionStatus::Active;
                        $amount = (int) $record->total_amount;

                        // Void ganda tidak perlu ditangkap di sini: ->visible()
                        // membuat Filament menolak me-mount MAUPUN menjalankan
                        // aksi untuk sesi yang sudah Voided, dan penjaga
                        // terakhirnya ada di VoidSessionAction (sudah ditest).
                        $voided = app(VoidSessionAction::class)->handle($record, auth()->user(), $data['reason']);

                        // Void bukan kabar baik — uang keluar dari laporan.
                        // Sengaja TIDAK hijau dan TIDAK hilang sendiri: kasir
                        // harus membaca angkanya lalu menutupnya, karena ini
                        // yang nanti dicocokkan saat menutup kas.
                        Notification::make()
                            ->title("Sesi {$voided->unit->code} dibatalkan")
                            ->body(implode(' ', array_filter([
                                $amount > 0
                                    ? Rupiah::format($amount).' dikeluarkan dari laporan pendapatan.'
                                    : 'Sesi belum sempat ditagih, jadi tidak ada nominal yang dikeluarkan.',
                                $wasActive ? 'TV unit ini ikut dimatikan.' : null,
                                'Tercatat atas nama '.auth()->user()->name.'.',
                            ])))
                            ->icon(Heroicon::OutlinedReceiptRefund)
                            ->warning()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    /**
     * Isi RichEditor yang benar-benar dibaca manusia.
     *
     * Filament v5 menyimpan state editor sebagai dokumen TipTap (array
     * bersarang), BUKAN string HTML — jadi memaksanya jadi string langsung
     * melempar "Array to string conversion". Teksnya ada di key 'text' pada
     * daun-daun dokumen; itu yang dikumpulkan di sini.
     */
    private static function plainText(mixed $state): string
    {
        if (! is_array($state)) {
            return trim(strip_tags((string) $state));
        }

        $text = '';

        array_walk_recursive($state, function (mixed $value, string|int $key) use (&$text): void {
            if ($key === 'text') {
                $text .= (string) $value;
            }
        });

        return trim($text);
    }
}
