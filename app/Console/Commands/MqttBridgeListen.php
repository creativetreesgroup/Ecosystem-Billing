<?php

namespace App\Console\Commands;

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\Events\UnitPowerStateChanged;
use App\Domain\Devices\PowerState;
use App\Domain\Devices\TasmotaTopic;
use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Throwable;

/**
 * Daemon jangka panjang (dikelola Supervisor, lihat deploy/supervisor) yang
 * mendengarkan status power unit Tasmota lewat Mosquitto — Tasmota mem-push
 * `stat/<control_ref>/POWER` tiap kali state berubah dan `tele/<control_ref>/LWT`
 * saat online/offline, jadi tidak perlu polling seperti unit Home Assistant
 * (lihat units:poll-state, yang memang tidak punya mekanisme push).
 *
 * Clean session dipakai supaya setiap kali daemon ini konek ulang setelah
 * downtime, ia langsung menerima retained message (state terkini tiap
 * device) alih-alih menunggu perubahan berikutnya.
 */
class MqttBridgeListen extends Command
{
    protected $signature = 'bridge:mqtt-listen';

    protected $description = 'Dengarkan status power unit Tasmota lewat MQTT (stat/+/POWER, tele/+/LWT).';

    private const int INITIAL_BACKOFF_SECONDS = 1;

    private const int MAX_BACKOFF_SECONDS = 30;

    public function handle(): int
    {
        $backoff = self::INITIAL_BACKOFF_SECONDS;

        while (true) {
            try {
                $this->listen();
                $backoff = self::INITIAL_BACKOFF_SECONDS;
            } catch (Throwable $e) {
                Log::error('bridge:mqtt-listen terputus, mencoba lagi.', ['error' => $e->getMessage(), 'retry_in_seconds' => $backoff]);
                $this->error("Terputus dari broker: {$e->getMessage()} — mencoba lagi dalam {$backoff}s.");

                sleep($backoff);
                $backoff = min($backoff * 2, self::MAX_BACKOFF_SECONDS);
            }
        }
    }

    private function listen(): void
    {
        $client = new MqttClient(config('services.mqtt.host'), config('services.mqtt.port'));

        $settings = (new ConnectionSettings)
            ->setUsername(config('services.mqtt.username'))
            ->setPassword(config('services.mqtt.password'))
            ->setKeepAliveInterval(30)
            ->setConnectTimeout(10);

        $client->connect($settings, true);

        $client->subscribe(TasmotaTopic::power('+'), fn (string $topic, string $message) => $this->handlePowerMessage($topic, $message), MqttClient::QOS_AT_LEAST_ONCE);
        $client->subscribe(TasmotaTopic::availability('+'), fn (string $topic, string $message) => $this->handleAvailabilityMessage($topic, $message), MqttClient::QOS_AT_LEAST_ONCE);

        $this->info('bridge:mqtt-listen tersambung ke '.config('services.mqtt.host').', mendengarkan...');

        $client->loop(true);
    }

    private function handlePowerMessage(string $topic, string $message): void
    {
        $unit = $this->unitForTopic($topic);

        if (! $unit) {
            return;
        }

        $this->applyState($unit, strtoupper(trim($message)) === 'ON' ? PowerState::On : PowerState::Standby);
    }

    private function handleAvailabilityMessage(string $topic, string $message): void
    {
        $unit = $this->unitForTopic($topic);

        if (! $unit) {
            return;
        }

        if (strtolower(trim($message)) === 'offline') {
            $this->applyState($unit, PowerState::Unreachable);

            return;
        }

        $unit->update(['last_seen_at' => now()]);
    }

    private function unitForTopic(string $topic): ?Unit
    {
        $controlRef = TasmotaTopic::controlRefFrom($topic);

        if (! $controlRef) {
            return null;
        }

        return Unit::query()
            ->where('control_driver', ControlDriver::Tasmota)
            ->where('control_ref', $controlRef)
            ->first();
    }

    private function applyState(Unit $unit, PowerState $state): void
    {
        $changed = $unit->power_state !== $state;

        $unit->update(['power_state' => $state, 'last_seen_at' => now()]);

        if ($changed) {
            UnitPowerStateChanged::dispatch($unit->id);
        }
    }
}
