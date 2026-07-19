<?php

namespace App\Console\Commands;

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceManager;
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

    private DeviceManager $devices;

    public function handle(DeviceManager $devices): int
    {
        $this->devices = $devices;
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

        // php-mqtt/client menangkap Throwable dari callback lalu mengirimnya ke
        // logger internalnya, yang di sini TIDAK diisi — artinya error saat
        // memproses pesan hilang tanpa jejak sama sekali. Dibungkus sendiri
        // supaya kegagalan tetap tercatat di log aplikasi (fail loud, §3.5)
        // tanpa mematikan daemon-nya.
        $client->subscribe(
            TasmotaTopic::power('+'),
            fn (string $topic, string $message) => $this->guard($topic, $message, fn () => $this->handlePowerMessage($topic, $message)),
            MqttClient::QOS_AT_LEAST_ONCE,
        );
        $client->subscribe(
            TasmotaTopic::availability('+'),
            fn (string $topic, string $message) => $this->guard($topic, $message, fn () => $this->handleAvailabilityMessage($topic, $message)),
            MqttClient::QOS_AT_LEAST_ONCE,
        );

        $this->info('bridge:mqtt-listen tersambung ke '.config('services.mqtt.host').', mendengarkan...');

        $client->loop(true);
    }

    private function handlePowerMessage(string $topic, string $message): void
    {
        $unit = $this->unitForTopic($topic);

        if (! $unit) {
            return;
        }

        $this->devices->reportState($unit, strtoupper(trim($message)) === 'ON' ? PowerState::On : PowerState::Standby);
    }

    private function handleAvailabilityMessage(string $topic, string $message): void
    {
        $unit = $this->unitForTopic($topic);

        if (! $unit) {
            return;
        }

        if (strtolower(trim($message)) === 'offline') {
            $this->devices->reportState($unit, PowerState::Unreachable);

            return;
        }

        // LWT "Online" hanya bilang plug-nya hidup — TIDAK membawa status relay.
        // Dulu baris ini menyegarkan last_seen_at, padahal itu satu-satunya
        // penanda kesegaran yang dipakai TasmotaDriver::state(): power_state
        // lama jadi terlihat "masih valid" tanpa ada yang memverifikasinya.
        // Sekarang kita minta status sebenarnya, dan biarkan stat/+/POWER yang
        // menyegarkan last_seen_at lewat reportState().
        $this->requestPowerState($unit);
    }

    /**
     * Tanya status relay ke plug (cmnd/<ref>/POWER tanpa payload = query).
     * Jawabannya datang sebagai stat/<ref>/POWER dan diproses seperti biasa.
     */
    private function requestPowerState(Unit $unit): void
    {
        $this->devices->tasmota()->queryState($unit);
    }

    /**
     * @param  callable():void  $handler
     */
    private function guard(string $topic, string $message, callable $handler): void
    {
        try {
            $handler();
        } catch (Throwable $e) {
            Log::error('Gagal memproses pesan MQTT.', [
                'topic' => $topic,
                'message' => $message,
                'error' => $e->getMessage(),
            ]);
        }
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
}
