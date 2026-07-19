<?php

namespace App\Filament\Resources\Integrations\Pages;

use App\Domain\Devices\DeviceManager;
use App\Filament\Resources\Integrations\IntegrationResource;
use App\Models\Integration;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

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
    protected function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label('Uji koneksi')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->action(function (): void {
                /** @var Integration $record */
                $record = $this->getRecord();

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
