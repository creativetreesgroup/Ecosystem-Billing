<?php

namespace App\Filament\Resources\Integrations\Pages;

use App\Domain\Billing\MidtransGateway;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\IntegrationKey;
use App\Filament\Resources\Integrations\IntegrationResource;
use App\Models\Integration;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Http;

class EditIntegration extends EditRecord
{
    protected static string $resource = IntegrationResource::class;

    /**
     * Tanpa DeleteAction: menghapus barisnya membuat sistem diam-diam kembali
     * ke nilai .env yang mungkin sudah kedaluwarsa. Untuk mematikan integrasi
     * ada toggle "Aktif" — niatnya terbaca dan bisa dikembalikan.
     */
    protected function getHeaderActions(): array
    {
        return [$this->testConnectionAction()];
    }

    /**
     * Tanpa ini, mengisi token hanyalah menebak: kesalahannya baru ketahuan
     * berjam-jam kemudian, saat kasir menekan tombol daya dan TV tidak
     * bereaksi — dan yang disalahkan akan TV-nya, bukan tokennya.
     *
     * Yang diuji sengaja bukan sekadar "API menjawab", melainkan "ada berapa
     * media_player yang terbaca": itu yang benar-benar menentukan daftar TV di
     * form unit bisa terisi atau tidak.
     */
    /**
     * Uji Midtrans dengan MENANYAKAN transaksi yang pasti tidak ada.
     *
     * Kredensial yang benar dijawab 404 "Transaction doesn't exist"; kredensial
     * yang salah dijawab 401. Membuat transaksi sungguhan hanya untuk menguji
     * akan meninggalkan tagihan hantu di dashboard Midtrans setiap kali tombol
     * ini ditekan.
     */
    protected function testMidtrans(Integration $record): void
    {
        if (! app(MidtransGateway::class)->isConfigured()) {
            Notification::make()
                ->title('Belum bisa diuji')
                ->body('Server key harus terisi lebih dulu, dan integrasinya aktif.')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->warning()
                ->send();

            return;
        }

        $gateway = app(MidtransGateway::class);
        $response = Http::withBasicAuth((string) $record->token, '')
            ->acceptJson()
            ->timeout(10)
            ->get($gateway->baseUrl().'/v2/CTB-UJI-KONEKSI-TIDAK-ADA/status');

        $mode = $record->option('is_production') ? 'PRODUKSI' : 'sandbox';

        // Midtrans menaruh kode statusnya DI DALAM body, bukan di status HTTP:
        // transaksi yang tidak ada dijawab HTTP 200 dengan status_code "404".
        // Membaca status HTTP saja membuat kredensial yang BENAR terbaca
        // sebagai "jawaban tak terduga" — sempat terjadi di sini.
        $kode = (int) ($response->json('status_code') ?? $response->status());

        if ($response->status() === 401 || $kode === 401) {
            Notification::make()
                ->title('Server key ditolak Midtrans')
                ->body("Kredensial tidak dikenali pada mode {$mode}. Pastikan server key & mode produksi cocok — kunci sandbox tidak berlaku di produksi, dan sebaliknya.")
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        if ($kode !== 404) {
            Notification::make()
                ->title('Midtrans menjawab tidak seperti yang diharapkan')
                ->body("Kode {$response->status()} pada mode {$mode}. Periksa alamat & koneksi internet mesin ini.")
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $record->update(['verified_at' => now()]);

        Notification::make()
            ->title('Midtrans terhubung')
            ->body("Server key diterima pada mode {$mode}. QRIS sudah bisa dipakai.")
            ->icon(Heroicon::OutlinedCheckCircle)
            ->success()
            ->send();
    }

    protected function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label('Uji koneksi')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->action(function (): void {
                /** @var Integration $record */
                $record = $this->getRecord();

                if ($record->key === IntegrationKey::Midtrans) {
                    $this->testMidtrans($record);

                    return;
                }

                if (! $record->isUsable()) {
                    Notification::make()
                        ->title('Belum bisa diuji')
                        ->body('Alamat dan token harus terisi lebih dulu, dan integrasinya aktif.')
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->warning()
                        ->send();

                    return;
                }

                $players = app(DeviceManager::class)->homeAssistant()->discoverMediaPlayers();

                if ($players === []) {
                    // Pesan ini TIDAK boleh menyebut isi token, alamat lengkap,
                    // atau isi respons: kredensial tidak pernah masuk pesan
                    // yang tampil di layar (§14). Yang disebut cuma langkah
                    // berikutnya.
                    Notification::make()
                        ->title('Tidak ada balasan yang bisa dipakai')
                        ->body('Home Assistant tidak menjawab, atau menjawab tanpa satu pun entity media_player. Periksa alamatnya benar dari mesin ini, tokennya belum dicabut, dan TV sudah terdaftar di Home Assistant.')
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $record->update(['verified_at' => now()]);

                Notification::make()
                    ->title('Home Assistant terhubung')
                    ->body(count($players).' entity media_player terbaca. Daftar "TV terdeteksi di jaringan" di form unit sekarang akan terisi.')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->success()
                    ->send();
            });
    }
}
