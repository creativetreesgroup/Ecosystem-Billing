<?php

namespace App\Domain\Devices\Drivers;

use App\Domain\Devices\Capability;
use App\Domain\Devices\CommandResult;
use App\Domain\Devices\PowerState;
use App\Domain\Devices\TasmotaTopic;
use App\Domain\Devices\TvControl;
use App\Models\Unit;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Throwable;

/**
 * Smart plug Tasmota lewat Mosquitto. Perintah power dipublish langsung
 * (fire-and-forget), tapi state TIDAK ditanya sinkron di sini — control_ref
 * jadi topic prefix, dan status sesungguhnya datang lewat `stat/+/POWER` /
 * `tele/+/LWT` yang ditangkap daemon bridge:mqtt-listen (lihat
 * MqttBridgeListenCommand) dan ditulis ke kolom power_state/last_seen_at.
 * state() di sini hanya membaca cache DB itu dengan cek staleness.
 */
class TasmotaDriver implements TvControl
{
    private const int STALE_AFTER_SECONDS = 120;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly ?string $username,
        private readonly ?string $password,
    ) {}

    public function powerOn(Unit $unit): CommandResult
    {
        return $this->publish($unit, 'ON');
    }

    public function powerOff(Unit $unit): CommandResult
    {
        return $this->publish($unit, 'OFF');
    }

    public function state(Unit $unit): PowerState
    {
        if (! $unit->last_seen_at || $unit->last_seen_at->diffInSeconds(now()) > self::STALE_AFTER_SECONDS) {
            return PowerState::Unknown;
        }

        return $unit->power_state;
    }

    public function supports(Unit $unit, Capability $capability): bool
    {
        return false;
    }

    public function notify(Unit $unit, string $message): CommandResult
    {
        Log::info('Notifikasi dilewati: unit tasmota (smart plug) tidak mendukung notify.', ['unit_id' => $unit->id]);

        return CommandResult::success('Tasmota: notifikasi tidak didukung, dilewati.');
    }

    private function publish(Unit $unit, string $payload): CommandResult
    {
        try {
            $client = new MqttClient($this->host, $this->port);

            $settings = (new ConnectionSettings)
                ->setUsername($this->username)
                ->setPassword($this->password)
                ->setConnectTimeout(3);

            $client->connect($settings);
            $client->publish(TasmotaTopic::command($unit->control_ref), $payload, MqttClient::QOS_AT_LEAST_ONCE);
            $client->disconnect();

            return CommandResult::success();
        } catch (Throwable $e) {
            return CommandResult::failure("Tidak bisa terhubung ke MQTT: {$e->getMessage()}");
        }
    }
}
