# Creative Trees Billing Game

Sistem billing rental PlayStation — Laravel 13 + Filament v5 + Reverb. Menangani uang sungguhan: satu sumber kebenaran untuk sesi, tarif, dan kontrol TV per unit.

Lihat juga: [`DECISIONS.md`](DECISIONS.md) (keputusan teknis & alasannya per fase) dan [`RUNBOOK.md`](RUNBOOK.md) (operasional harian, insiden, tugas manusia).

## Prinsip arsitektur

1. **Laravel adalah satu-satunya source of truth.** Home Assistant dan Tasmota hanya *tangan* (eksekutor). State billing tidak pernah bergantung pada state device.
2. **Waktu selalu otoritas server.** Durasi dan biaya dihitung dari `started_at`/`ends_at`/`ended_at` di server; timer di browser hanya tampilan.
3. **Uang = integer rupiah.** Tidak ada float di kolom, kalkulasi, maupun response.
4. **Realtime terukur:** perubahan state tampil di dashboard ≤ 2 detik via Reverb, dengan fallback polling 15 detik jika WebSocket putus.
5. **Fail loud, fail secure.** Perintah device yang gagal menghasilkan alert yang terlihat kasir, bukan kegagalan diam-diam.

## Topologi komponen

```mermaid
flowchart LR
    Kasir["Browser Kasir/Owner<br/>(Filament panel, LAN only)"]

    subgraph Server["Mini PC — Ubuntu Server"]
        Laravel["Laravel App<br/>(Nginx + PHP-FPM)"]
        Reverb["Reverb<br/>(WebSocket server)"]
        Queue["Queue Worker<br/>(database driver)"]
        Scheduler["Scheduler<br/>(units:poll-state, sweep)"]
        Bridge["bridge:mqtt-listen<br/>(daemon)"]
        MySQL[("MySQL 8")]
    end

    HA["Home Assistant<br/>(Docker, network_mode: host)"]
    Mosquitto["Mosquitto<br/>(Docker)"]
    TV["Smart TV<br/>(Android TV: Sony/TCL/Coocaa)"]
    Plug["Smart Plug<br/>(Tasmota firmware)"]

    Kasir <-->|HTTP| Laravel
    Kasir <-->|WebSocket| Reverb
    Laravel --> MySQL
    Laravel --> Queue
    Queue --> MySQL
    Scheduler --> Laravel
    Laravel -->|REST API, Bearer token| HA
    HA -->|HDMI-CEC / network standby| TV
    Bridge <-->|MQTT pub/sub| Mosquitto
    Laravel -.->|publish cmnd/+/POWER| Mosquitto
    Mosquitto <-->|stat/+/POWER, tele/+/LWT| Plug
    Bridge --> MySQL
    Laravel -->|broadcast events| Reverb
```

## ERD

```mermaid
erDiagram
    OUTLETS ||--o{ USERS : employs
    OUTLETS ||--o{ UNIT_TYPES : offers
    OUTLETS ||--o{ UNITS : has
    UNIT_TYPES ||--o{ UNITS : categorizes
    UNIT_TYPES ||--o{ PACKAGES : offers
    UNITS ||--o{ RENTAL_SESSIONS : hosts
    PACKAGES ||--o{ RENTAL_SESSIONS : "priced by"
    USERS ||--o{ RENTAL_SESSIONS : opens
    RENTAL_SESSIONS ||--o{ SESSION_EXTENSIONS : extended_by
    USERS ||--o{ SESSION_EXTENSIONS : records
    UNITS ||--o{ DEVICE_ALERTS : raises

    OUTLETS {
        bigint id PK
        string name
        string timezone
        bool is_active
    }
    USERS {
        bigint id PK
        bigint outlet_id FK
        string name
        string email UK
        string password
        enum role "owner, kasir"
        bool is_active
    }
    UNIT_TYPES {
        bigint id PK
        bigint outlet_id FK
        string name
        uint hourly_rate "rupiah"
        uint sort_order
    }
    UNITS {
        bigint id PK
        bigint outlet_id FK
        bigint unit_type_id FK
        string code UK "per outlet"
        enum control_driver "home_assistant, tasmota, manual"
        string control_ref "entity_id HA / topic Tasmota"
        string tv_mac "Wake-on-LAN"
        json capabilities
        enum power_state "on, standby, unreachable, unknown"
        timestamp last_seen_at
        bool is_active
    }
    PACKAGES {
        bigint id PK
        bigint unit_type_id FK
        string name
        uint duration_minutes
        uint price "rupiah"
        bool is_active
    }
    RENTAL_SESSIONS {
        bigint id PK
        bigint unit_id FK
        bigint opened_by FK
        string customer_name
        enum type "open, package"
        bigint package_id FK
        timestamp started_at
        timestamp ends_at "null untuk open play"
        timestamp ended_at
        enum status "active, completed, voided"
        uuid expiry_token
        uint base_amount "rupiah"
        uint extra_amount "rupiah"
        uint total_amount "rupiah"
        enum payment_method "cash, qris, transfer"
        timestamp paid_at
        bigint voided_by FK
        text void_reason
        bigint active_unit_id "generated, unique — 1 sesi aktif/unit"
    }
    SESSION_EXTENSIONS {
        bigint id PK
        bigint rental_session_id FK
        uint added_minutes
        uint amount "rupiah"
        bigint user_id FK
    }
    DEVICE_ALERTS {
        bigint id PK
        bigint unit_id FK
        enum type "power_off_failed, device_offline, state_mismatch"
        string message
        enum status "open, acknowledged"
        bigint acknowledged_by FK
        timestamp acknowledged_at
    }
    SETTINGS {
        bigint id PK
        string key UK
        json value
    }
```

`outlet_id` ada di `users`/`unit_types`/`units` sejak V1 sebagai fondasi siap-scale — V1 sendiri berjalan single-outlet, tanpa UI ganti-outlet (lihat `DECISIONS.md` Fase 0).

## Sequence: sesi berakhir → TV mati

```mermaid
sequenceDiagram
    participant Job as ExpireRentalSession<br/>(delayed job / sweep)
    participant Action as CompleteSessionAction
    participant DB as MySQL
    participant DM as DeviceManager
    participant Driver as HomeAssistantDriver /<br/>TasmotaDriver
    participant Verify as VerifyUnitPoweredOffJob<br/>(+10s)
    participant Reverb
    participant Dash as Dashboard Kasir

    Job->>Action: handle(session)
    Action->>DB: lockForUpdate() + update status=completed
    Action->>DM: powerOff(unit)
    DM->>Driver: powerOff(unit)
    Driver-->>DM: CommandResult
    DM->>Job: dispatch VerifyUnitPoweredOffJob (delay 10s)
    Action->>Reverb: broadcast SessionEnded
    Reverb-->>Dash: push (≤2s)
    Dash->>Dash: refreshUnits() — tanpa reload

    Note over Verify: 10 detik kemudian
    Verify->>Driver: state(unit)
    alt masih On
        Verify->>DB: create device_alert (state_mismatch)
        DB-->>Reverb: broadcast DeviceAlertRaised
        Reverb-->>Dash: badge alert muncul
    else Standby/Off
        Verify->>Verify: no-op
    end
```

## Menjalankan lokal

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
composer run dev   # server + queue:listen + reverb:start + schedule:work, paralel
```

**Tidak ada build step frontend sama sekali** — tanpa Node, tanpa npm, tanpa Vite. Seluruh antarmuka memakai Filament yang aset CSS/JS-nya sudah ter-compile dan ter-publish ke `public/` oleh `php artisan filament:upgrade` (sudah otomatis lewat `post-autoload-dump` di `composer.json`).

Unit dengan `control_driver=manual` tidak butuh Home Assistant/Mosquitto apa pun — cukup untuk development. Untuk mencoba driver HA/Tasmota sungguhan, lihat `docker-compose.devices.yml` (Linux only, lihat komentar di file itu) dan `RUNBOOK.md`.

## Test

```bash
php artisan test              # Feature + Unit
php artisan test --testsuite=Concurrency   # butuh DB nyata, bukan transaksi in-memory
```

## Stack (versi dikunci)

PHP 8.5 · Laravel 13 · Filament v5 (Livewire 4) · MySQL 8 · Reverb · Pest v4 · `php-mqtt/client` · `spatie/laravel-activitylog` · `laravel/boost`.
