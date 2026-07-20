<?php

namespace App\Domain\Devices;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Socket;
use Throwable;

/**
 * Memindai jaringan lokal sendiri, tanpa perantara Home Assistant.
 *
 * Sebelum ini satu-satunya cara sistem tahu ada TV di jaringan adalah bertanya
 * ke Home Assistant. Kalau HA mati, belum diatur, atau tokennya salah, sistem
 * buta total — dan operator tidak punya cara membedakan "TV-nya mati" dari
 * "HA-nya yang bermasalah". Pemindai ini menjawab pertanyaan yang lebih dasar
 * dan berdiri sendiri: perangkat ini benar-benar ada di jaringan kita atau
 * tidak?
 *
 * Caranya SSDP (UPnP discovery): satu paket M-SEARCH ke 239.255.255.250:1900,
 * lalu kumpulkan yang menjawab. Ini yang juga dipakai Home Assistant sendiri,
 * jadi tidak ada dependensi baru — cukup ext-sockets yang sudah aktif.
 */
class NetworkScanner
{
    private const MULTICAST_ADDRESS = '239.255.255.250';

    private const MULTICAST_PORT = 1900;

    /**
     * Tiga target: ssdp:all menjaring semuanya, dua sisanya menyasar TV secara
     * langsung — MediaRenderer untuk DLNA (Samsung/LG/Sony), DIAL untuk
     * Chromecast & Android TV (Coocaa/TCL dan merek lokal lainnya).
     */
    private const SEARCH_TARGETS = [
        'ssdp:all',
        'urn:schemas-upnp-org:device:MediaRenderer:1',
        'urn:dial-multiscreen-org:service:dial:1',
    ];

    /**
     * @return array<int, DiscoveredDevice> diurutkan berdasarkan IP
     */
    public function scan(int $timeoutSeconds = 4): array
    {
        $responses = $this->collectSsdpResponses($timeoutSeconds);

        if ($responses === []) {
            return [];
        }

        return collect($responses)
            ->map(fn (array $headers, string $ip) => $this->describe($ip, $headers))
            ->sortBy(fn (DiscoveredDevice $device) => $device->ip)
            ->values()
            ->all();
    }

    /**
     * @return array<int, DiscoveredDevice>
     */
    public function scanTelevisions(int $timeoutSeconds = 4): array
    {
        return array_values(array_filter(
            $this->scan($timeoutSeconds),
            fn (DiscoveredDevice $device) => $device->looksLikeTelevision(),
        ));
    }

    /**
     * TV hasil pindai, siap dipakai form, dikunci berdasarkan MAC.
     *
     * Di-cache sebentar karena Filament mengevaluasi closure schema lebih dari
     * sekali dalam SATU permintaan — tanpa ini, membuka satu modal memicu
     * pemindaian 4 detik beberapa kali berturut-turut. Perangkat tanpa MAC
     * dibuang: tanpa MAC tidak ada yang bisa diisikan ke tv_mac.
     *
     * @return array<string, array{ip: string, name: ?string, label: string}>
     */
    public function televisionsForPicker(): array
    {
        return Cache::remember('devices.scan.televisions', now()->addMinute(), function (): array {
            $found = [];

            foreach ($this->scanTelevisions() as $device) {
                if ($device->mac === null) {
                    continue;
                }

                $found[$device->mac] = [
                    'ip' => $device->ip,
                    'name' => $device->name,
                    'label' => $device->labelWithIp(),
                ];
            }

            return $found;
        });
    }

    /**
     * @return array<string, string> MAC => "TCL Smart TV (192.168.100.7)"
     */
    public function televisionOptions(): array
    {
        return array_map(fn (array $device): string => $device['label'], $this->televisionsForPicker());
    }

    /**
     * Alamat Home Assistant di jaringan ini, dicari dengan menyapu /24 pada
     * port 8123.
     *
     * SSDP tidak dipakai di sini: HA hanya mengiklankan diri lewat SSDP kalau
     * integrasi ssdp-nya aktif, sedangkan port 8123 SELALU terbuka begitu HA
     * berjalan — itu satu-satunya penanda yang bisa diandalkan pada instalasi
     * yang baru dipasang, yang justru saat pemindaian ini paling dibutuhkan.
     *
     * Sambungannya asinkron dan dibuka serentak: satu per satu dengan timeout
     * yang layak akan memakan menit, bukan detik.
     *
     * @return array<int, string> mis. ["http://192.168.100.10:8123"]
     */
    public function findHomeAssistant(int $port = 8123): array
    {
        $prefix = preg_replace('/\.\d+$/', '', $this->localAddress());

        if ($prefix === null || $prefix === '' || $prefix === '0.0.0') {
            return [];
        }

        $pending = [];

        for ($host = 1; $host <= 254; $host++) {
            $ip = "{$prefix}.{$host}";
            $socket = @stream_socket_client(
                "tcp://{$ip}:{$port}",
                $errorCode,
                $errorMessage,
                0.1,
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT,
            );

            if ($socket !== false) {
                $pending[$ip] = $socket;
            }
        }

        if ($pending === []) {
            return [];
        }

        // Satu jeda untuk SEMUA sambungan sekaligus, bukan per alamat.
        usleep(900_000);

        $found = [];

        foreach ($pending as $ip => $socket) {
            $read = [];
            $write = [$socket];
            $except = [];

            if (@stream_select($read, $write, $except, 0, 0) > 0 && @stream_socket_get_name($socket, true)) {
                $found[] = "http://{$ip}:{$port}";
            }

            fclose($socket);
        }

        return $found;
    }

    /**
     * Satu balasan per IP — perangkat menjawab berkali-kali untuk tiap layanan
     * yang ia iklankan, dan operator tidak peduli pada layanannya.
     *
     * @return array<string, array<string, string>> IP => header
     */
    private function collectSsdpResponses(int $timeoutSeconds): array
    {
        $socket = $this->openSocket();

        if (! $socket instanceof Socket) {
            return [];
        }

        foreach (self::SEARCH_TARGETS as $target) {
            $message = implode("\r\n", [
                'M-SEARCH * HTTP/1.1',
                'HOST: '.self::MULTICAST_ADDRESS.':'.self::MULTICAST_PORT,
                'MAN: "ssdp:discover"',
                'MX: 2',
                "ST: {$target}",
                '', '',
            ]);

            @socket_sendto($socket, $message, strlen($message), 0, self::MULTICAST_ADDRESS, self::MULTICAST_PORT);
        }

        $found = [];
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $buffer = '';
            $from = '';
            $port = 0;

            if (@socket_recvfrom($socket, $buffer, 4096, MSG_DONTWAIT, $from, $port) === false || $buffer === '') {
                usleep(100_000);

                continue;
            }

            $headers = self::parseHeaders($buffer);

            // Balasan berikutnya dari IP yang sama hanya dipakai untuk
            // melengkapi header yang belum sempat terisi.
            $found[$from] = [...($found[$from] ?? []), ...$headers];
        }

        socket_close($socket);

        return $found;
    }

    /**
     * Socket WAJIB di-bind ke alamat LAN, bukan 0.0.0.0.
     *
     * Mesin dengan lebih dari satu interface (WiFi + Ethernet + adapter
     * virtual) mengirim multicast lewat interface yang dipilih kernel, yang
     * belum tentu interface outlet — hasilnya nol balasan padahal TV-nya
     * jelas menyala. IP_MULTICAST_IF tidak dipakai karena macOS menolak
     * nilai berupa alamat IP.
     */
    private function openSocket(): ?Socket
    {
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if (! $socket instanceof Socket) {
            Log::warning('Pemindaian jaringan gagal: socket tidak bisa dibuat.');

            return null;
        }

        @socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 4);

        if (! @socket_bind($socket, $this->localAddress(), 0)) {
            Log::warning('Pemindaian jaringan gagal: socket tidak bisa di-bind.', [
                'error' => socket_strerror(socket_last_error($socket)),
            ]);
            socket_close($socket);

            return null;
        }

        return $socket;
    }

    /**
     * Alamat LAN mesin ini. UDP "connect" tidak mengirim paket apa pun — ia
     * hanya memaksa kernel memilih rute, lalu alamat sumbernya kita baca.
     */
    private function localAddress(): string
    {
        $probe = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if (! $probe instanceof Socket) {
            return '0.0.0.0';
        }

        $address = '0.0.0.0';

        if (@socket_connect($probe, self::MULTICAST_ADDRESS, self::MULTICAST_PORT)) {
            @socket_getsockname($probe, $address);
        }

        socket_close($probe);

        return $address;
    }

    /**
     * Melengkapi hasil pemindaian dengan nama & model dari deskripsi perangkat.
     *
     * Tanpa ini yang terbaca cuma "Linux/4.14.198+, UPnP/1.0, Chromecast/1.6.18"
     * — benar, tapi tidak membantu operator mencocokkan dengan TV di ruangan.
     * Gagal mengambil deskripsi tidak apa-apa: perangkatnya tetap dilaporkan
     * ada, hanya namanya kurang ramah.
     */
    private function describe(string $ip, array $headers): DiscoveredDevice
    {
        $location = $headers['location'] ?? null;
        $server = $headers['server'] ?? null;

        $name = $model = $manufacturer = null;

        if ($location) {
            [$name, $model, $manufacturer] = $this->fetchDescription($location);
        }

        return new DiscoveredDevice(
            ip: $ip,
            name: $name,
            model: $model,
            manufacturer: $manufacturer,
            server: $server,
            location: $location,
            mac: $this->macFor($ip),
        );
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function fetchDescription(string $location): array
    {
        try {
            $response = Http::timeout(2)->get($location);

            if (! $response->successful()) {
                return [null, null, null];
            }

            return self::parseDescription($response->body());
        } catch (Throwable $exception) {
            Log::info('Deskripsi perangkat tidak bisa diambil.', [
                'location' => $location,
                'error' => $exception->getMessage(),
            ]);

            return [null, null, null];
        }
    }

    /**
     * MAC perangkat, dibaca dari tabel ARP kernel.
     *
     * SSDP hanya memberi IP. MAC-nya dibutuhkan untuk Wake-on-LAN, dan
     * mengetiknya manual adalah sumber kesalahan yang tidak pernah kelihatan:
     * WoL yang salah alamat gagal dalam diam, TV tidak menyala, dan kasir
     * menyimpulkan sistemnya rusak.
     *
     * IP-nya berasal dari alamat pengirim paket UDP (bukan input pengguna),
     * tapi tetap di-escape sebelum masuk shell.
     */
    private function macFor(string $ip): ?string
    {
        // Linux (server outlet): baca langsung, tanpa memanggil shell.
        if (is_readable('/proc/net/arp')) {
            foreach (file('/proc/net/arp', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $columns = preg_split('/\s+/', trim($line)) ?: [];

                if (($columns[0] ?? null) === $ip) {
                    return self::normaliseMac($columns[3] ?? '');
                }
            }

            return null;
        }

        // macOS (mesin pengembangan): tidak punya /proc.
        $output = @shell_exec('arp -n '.escapeshellarg($ip).' 2>/dev/null');

        if (! is_string($output) || preg_match('/([0-9a-f]{1,2}(?::[0-9a-f]{1,2}){5})/i', $output, $matches) !== 1) {
            return null;
        }

        return self::normaliseMac($matches[1]);
    }

    /**
     * Menyeragamkan MAC ke bentuk aa:bb:cc:dd:ee:ff.
     *
     * WAJIB: `arp` di macOS membuang nol di depan tiap oktet — router di
     * jaringan uji terbaca "80:60:36:69:2f:6". WakeOnLan::send() menuntut
     * tepat 12 digit heksa, jadi bentuk pendek itu ditolak dalam diam dan
     * TV-nya tidak pernah dibangunkan.
     */
    public static function normaliseMac(string $raw): ?string
    {
        $parts = preg_split('/[:-]/', trim($raw)) ?: [];

        if (count($parts) !== 6) {
            return null;
        }

        $octets = [];

        foreach ($parts as $part) {
            if (preg_match('/^[0-9a-f]{1,2}$/i', $part) !== 1) {
                return null;
            }

            $octets[] = strtolower(str_pad($part, 2, '0', STR_PAD_LEFT));
        }

        return implode(':', $octets);
    }

    /**
     * @return array<string, string> nama header huruf kecil => nilai
     */
    public static function parseHeaders(string $raw): array
    {
        $headers = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if ($name === '' || $value === '') {
                continue;
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Dibaca dengan regex, bukan parser XML: deskripsi UPnP dari perangkat
     * murah sering cacat (namespace ganda, tag tidak ditutup) sehingga
     * simplexml gagal total — padahal yang dibutuhkan cuma tiga nilai.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} nama, model, merek
     */
    public static function parseDescription(string $xml): array
    {
        $read = function (string $tag) use ($xml): ?string {
            if (preg_match("#<{$tag}>(.*?)</{$tag}>#is", $xml, $matches) !== 1) {
                return null;
            }

            $value = trim(html_entity_decode($matches[1]));

            return $value === '' ? null : $value;
        };

        return [$read('friendlyName'), $read('modelName'), $read('manufacturer')];
    }
}
