<?php

namespace App\Domain\Devices\Drivers;

use App\Domain\Devices\Capability;
use App\Domain\Devices\CommandResult;
use App\Domain\Devices\NetworkScanner;
use App\Domain\Devices\PowerState;
use App\Domain\Devices\TvControl;
use App\Domain\Devices\WakeOnLan;
use App\Models\Unit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * REST API Home Assistant. entity_id (control_ref) dikontrol lewat domain
 * media_player, yang bekerja seragam lintas integrasi (Android TV, webOS,
 * Bravia, dst) karena HA mengabstraksikan semuanya ke entity yang sama —
 * lihat https://developers.home-assistant.io/docs/api/rest/.
 */
class HomeAssistantDriver implements TvControl
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    /**
     * Apakah HA sudah dikonfigurasi sama sekali.
     *
     * Dibedakan dari "terhubung tapi tidak menemukan apa-apa": tanpa pembedaan
     * ini, daftar TV yang kosong terbaca sebagai "tidak ada TV di jaringan"
     * padahal TV-nya jelas ada dan yang belum ada justru tokennya.
     */
    public function isConfigured(): bool
    {
        return filled($this->baseUrl) && filled($this->token);
    }

    public function powerOn(Unit $unit): CommandResult
    {
        if ($unit->tv_mac) {
            // Best-effort sungguhan: socket_sendto() memunculkan E_WARNING yang
            // diubah Laravel jadi ErrorException, dan dulu itu membatalkan
            // perintah turn_on di bawahnya — WoL yang cuma cadangan malah
            // menggagalkan jalur utamanya.
            try {
                WakeOnLan::send($unit->tv_mac);
            } catch (Throwable $e) {
                Log::warning('Wake-on-LAN gagal, lanjut ke perintah Home Assistant.', [
                    'unit_id' => $unit->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->callService('media_player', 'turn_on', $unit->control_ref);
    }

    public function powerOff(Unit $unit): CommandResult
    {
        return $this->callService('media_player', 'turn_off', $unit->control_ref);
    }

    /**
     * TV standby tetap hidup di jaringan; TV yang dicabut tidak. MAC-nya
     * dipakai sebagai penanda, jadi unit tanpa tv_mac tetap dianggap
     * tidak terhubung — tidak ada bukti untuk menyimpulkan sebaliknya.
     */
    private function isStandbyRatherThanGone(Unit $unit): PowerState
    {
        if (blank($unit->tv_mac)) {
            return PowerState::Unreachable;
        }

        return app(NetworkScanner::class)->isMacOnNetwork($unit->tv_mac)
            ? PowerState::Standby
            : PowerState::Unreachable;
    }

    public function state(Unit $unit): PowerState
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout(3)
                ->retry(1, 100, throw: false)
                ->get("{$this->baseUrl}/api/states/{$unit->control_ref}");

            if (! $response->successful()) {
                return PowerState::Unreachable;
            }

            // media_player TIDAK hanya melaporkan 'on'. Android TV/Chromecast/
            // webOS melaporkan playing/paused/idle/buffering saat TV MENYALA —
            // dulu semuanya jatuh ke Unknown, sehingga verifikasi power-off
            // menganggap TV yang jelas menyala sebagai "tidak diketahui" dan
            // tidak pernah membuat alert.
            return match ($response->json('state')) {
                'on', 'playing', 'paused', 'idle', 'buffering' => PowerState::On,
                'off', 'standby' => PowerState::Standby,
                // "unavailable" dari Home Assistant berarti DUA hal yang
                // sangat berbeda, dan HA tidak bisa membedakannya: Android TV
                // memutus koneksi remote-nya saat standby, persis seperti saat
                // dicabut. Tanpa pembedaan ini, SETIAP unit yang menganggur
                // melahirkan alert "Tidak terhubung" sepanjang malam — dan
                // gangguan yang sungguhan tenggelam di antara ratusan yang
                // palsu. Ditemukan saat UAT di TCL sungguhan.
                'unavailable' => $this->isStandbyRatherThanGone($unit),
                default => PowerState::Unknown,
            };
        } catch (ConnectionException $e) {
            Log::warning('Home Assistant tidak bisa dihubungi saat cek state.', [
                'unit_id' => $unit->id,
                'error' => $e->getMessage(),
            ]);

            return PowerState::Unreachable;
        }
    }

    public function supports(Unit $unit, Capability $capability): bool
    {
        return in_array($capability->value, $unit->capabilities ?? [], true);
    }

    /**
     * Semua TV yang sudah ditemukan Home Assistant di jaringan yang sama —
     * HA-lah yang menjalankan discovery mDNS/SSDP-nya, jadi begitu sebuah TV
     * menyala di WiFi/LAN yang sama ia otomatis muncul di sini tanpa perlu
     * tahu IP-nya sama sekali (IP boleh berubah; entity_id tidak).
     *
     * @return array<string, string> entity_id => nama yang tampil di HA
     */
    public function discoverMediaPlayers(): array
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout(5)
                ->get("{$this->baseUrl}/api/states");

            if (! $response->successful()) {
                return [];
            }
        } catch (ConnectionException $e) {
            Log::warning('Home Assistant tidak bisa dihubungi saat discovery TV.', ['error' => $e->getMessage()]);

            return [];
        }

        return collect($response->json())
            ->filter(fn (array $entity) => str_starts_with($entity['entity_id'] ?? '', 'media_player.'))
            ->mapWithKeys(fn (array $entity) => [
                $entity['entity_id'] => $entity['attributes']['friendly_name'] ?? $entity['entity_id'],
            ])
            ->sort()
            ->all();
    }

    public function notify(Unit $unit, string $message): CommandResult
    {
        if (! $this->supports($unit, Capability::Notify)) {
            Log::info('Notifikasi dilewati: unit tidak mendukung notify.', ['unit_id' => $unit->id]);

            return CommandResult::success('Notifikasi tidak didukung, dilewati.');
        }

        return $this->callService('notify', 'notify', null, [
            'message' => $message,
            'target' => $unit->control_ref,
        ]);
    }

    private function callService(string $domain, string $service, ?string $entityId, array $extra = []): CommandResult
    {
        $payload = $entityId ? ['entity_id' => $entityId, ...$extra] : $extra;

        try {
            $response = Http::withToken($this->token)
                ->timeout(3)
                ->retry(1, 100, throw: false)
                ->post("{$this->baseUrl}/api/services/{$domain}/{$service}", $payload);

            return $response->successful()
                ? CommandResult::success()
                : CommandResult::failure("Home Assistant merespons status {$response->status()}.");
        } catch (ConnectionException $e) {
            return CommandResult::failure("Tidak bisa terhubung ke Home Assistant: {$e->getMessage()}");
        }
    }
}
