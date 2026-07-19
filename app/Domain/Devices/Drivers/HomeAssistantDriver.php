<?php

namespace App\Domain\Devices\Drivers;

use App\Domain\Devices\Capability;
use App\Domain\Devices\CommandResult;
use App\Domain\Devices\PowerState;
use App\Domain\Devices\TvControl;
use App\Domain\Devices\WakeOnLan;
use App\Models\Unit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    public function powerOn(Unit $unit): CommandResult
    {
        if ($unit->tv_mac) {
            WakeOnLan::send($unit->tv_mac);
        }

        return $this->callService('media_player', 'turn_on', $unit->control_ref);
    }

    public function powerOff(Unit $unit): CommandResult
    {
        return $this->callService('media_player', 'turn_off', $unit->control_ref);
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

            return match ($response->json('state')) {
                'on' => PowerState::On,
                'off', 'standby' => PowerState::Standby,
                'unavailable' => PowerState::Unreachable,
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
