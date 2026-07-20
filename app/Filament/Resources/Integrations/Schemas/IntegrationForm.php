<?php

namespace App\Filament\Resources\Integrations\Schemas;

use App\Domain\Devices\IntegrationKey;
use App\Domain\Devices\NetworkScanner;
use App\Models\Integration;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

class IntegrationForm
{
    /**
     * Mencari sendiri alamat Home Assistant di jaringan.
     *
     * Ini satu-satunya dari tiga langkah pemasangan yang BISA diotomatiskan.
     * Token harus dibuat manusia di dalam HA, dan kode pairing 6 digit harus
     * dibaca dari layar TV — keduanya memang titik keamanan, bukan kekurangan
     * yang bisa ditambal. Alamatnya sekadar fakta jaringan, jadi tidak ada
     * alasan menyuruh operator mengetiknya.
     */
    private static function findOnNetworkAction(): Action
    {
        return Action::make('findHomeAssistant')
            ->label('Cari di jaringan')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->modalWidth(Width::Medium)
            ->modalAlignment(Alignment::Center)
            ->modalFooterActionsAlignment(Alignment::Center)
            ->modalIcon(fn (): string => self::foundAddresses() === []
                ? 'heroicon-o-magnifying-glass-circle'
                : 'heroicon-o-check-circle')
            ->modalIconColor(fn (): string => self::foundAddresses() === [] ? 'warning' : 'success')
            ->modalHeading(fn (): string => self::foundAddresses() === []
                ? 'Home Assistant tidak ditemukan'
                : 'Home Assistant ditemukan')
            ->modalDescription(fn (): ?string => self::foundAddresses() === []
                ? 'Tidak ada yang membuka port 8123 di jaringan ini. Pastikan Home Assistant sudah terpasang dan berjalan, dan berada di jaringan yang sama dengan mesin ini.'
                : 'Pilih alamat yang benar, lalu isikan tokennya secara terpisah.')
            ->modalSubmitActionLabel('Pakai alamat ini')
            ->modalCancelActionLabel('Tutup')
            ->schema(fn (): array => self::foundAddresses() === [] ? [] : [
                Radio::make('base_url')
                    ->hiddenLabel()
                    ->options(array_combine(self::foundAddresses(), self::foundAddresses()))
                    ->required(),
            ])
            ->action(fn (array $data, Set $set) => $set('base_url', $data['base_url']));
    }

    /**
     * Di-cache sebentar: Filament mengevaluasi closure schema lebih dari sekali
     * dalam SATU permintaan, dan penyapuan /24 memakan waktu nyata.
     *
     * @return array<int, string>
     */
    private static function foundAddresses(): array
    {
        return Cache::remember(
            'integrations.home-assistant.found',
            now()->addMinute(),
            fn (): array => app(NetworkScanner::class)->findHomeAssistant(),
        );
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(fn (?Integration $record): string => $record?->key?->getLabel() ?? 'Integrasi')
                    ->description(fn (?Integration $record): ?string => $record?->key?->description())
                    ->icon(fn (?Integration $record) => $record?->key?->getIcon())
                    // ['md' => 2], bukan columns(2) — columns(2) di Filament
                    // berarti ['lg' => 2] dan tablet ikut menumpuk seperti HP.
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('base_url')
                            ->label('Alamat')
                            ->placeholder(fn (?Integration $record): ?string => $record?->key?->baseUrlPlaceholder())
                            ->url()
                            ->helperText('Alamat Home Assistant dari sudut pandang mesin ini. Kalau HA berjalan di mesin lain, gunakan IP-nya — bukan localhost.')
                            ->suffixAction(self::findOnNetworkAction())
                            ->visible(fn (?Integration $record): bool => $record?->key !== null)
                            ->columnSpanFull(),

                        // Token TIDAK PERNAH dikirim balik ke browser.
                        //
                        // dehydrated hanya saat diisi: mengosongkan kolom ini
                        // berarti "tidak diubah", bukan "hapus tokennya" —
                        // kalau tidak, setiap penyimpanan kecil (mis. mengubah
                        // alamat saja) akan menghapus token diam-diam dan
                        // memutus seluruh kendali TV outlet.
                        TextInput::make('token')
                            ->label(fn (?Integration $record): string => $record?->key === IntegrationKey::Midtrans ? 'Server key' : 'Long-lived access token')
                            ->password()
                            ->revealable(false)
                            ->autocomplete(false)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                            ->placeholder(fn (?Integration $record): string => $record?->maskedToken() ?? 'Belum diisi')
                            ->helperText(fn (?Integration $record): string => $record?->key === IntegrationKey::Midtrans
                                ? 'Ambil di Midtrans Dashboard → Settings → Access Keys → Server Key. Ini RAHASIA — jangan pernah dipakai di sisi pelanggan. Kosongkan bila tidak ingin menggantinya.'
                                : 'Buat di Home Assistant: klik nama Anda (kiri bawah) → tab Security → Long-lived access tokens → Create Token. Token hanya muncul sekali. Kosongkan bila tidak ingin menggantinya.')
                            ->columnSpanFull(),

                        // Hanya untuk Midtrans. Client key & merchant id BUKAN
                        // rahasia — keduanya memang dipakai terbuka di sisi
                        // pelanggan — jadi ditampilkan apa adanya, tidak
                        // disamarkan seperti server key.
                        TextInput::make('options.client_key')
                            ->label('Client key')
                            ->placeholder('SB-Mid-client-xxxxxxxx')
                            ->visible(fn (?Integration $record): bool => $record?->key === IntegrationKey::Midtrans),
                        TextInput::make('options.merchant_id')
                            ->label('Merchant ID')
                            ->placeholder('G123456789')
                            ->visible(fn (?Integration $record): bool => $record?->key === IntegrationKey::Midtrans),
                        Toggle::make('options.is_production')
                            ->label('Mode produksi')
                            // Salah mode adalah kegagalan DIAM yang paling
                            // mahal: transaksi sandbox terlihat sukses di layar
                            // padahal tidak ada uang yang pernah berpindah.
                            ->helperText('Nyalakan hanya bila memakai kredensial produksi. Kredensial sandbox pada mode produksi akan selalu ditolak — dan sebaliknya, transaksi sandbox terlihat berhasil tanpa uang benar-benar masuk.')
                            ->visible(fn (?Integration $record): bool => $record?->key === IntegrationKey::Midtrans)
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Dimatikan berarti sistem kembali memakai nilai dari .env, bukan berhenti mencoba.')
                            ->default(true),

                        TextEntry::make('verified_at')
                            ->label('Terakhir diuji')
                            ->state(fn (?Integration $record): string => $record?->verified_at
                                ? $record->verified_at->setTimezone(config('app.display_timezone'))->format('d M Y H:i')
                                : 'Belum pernah diuji')
                            ->color(fn (?Integration $record): string => $record?->verified_at ? 'success' : 'gray'),
                    ]),
            ]);
    }
}
