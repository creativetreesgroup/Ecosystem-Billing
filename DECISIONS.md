# Decisions Log

Format: keputusan, alasan (*why*), dan trade-off yang ditolak. Diurutkan per fase.

## Fase 0 — Scope

- **V1 dibangun sesuai batasan asli (§1 non-goals), dengan fondasi siap-scale.**
  *Why:* jawaban awal user memperluas scope ke multi-outlet, akun pelanggan + saldo,
  payment gateway (Midtrans), WhatsApp, dan diskon — semuanya eksplisit non-goal v1
  di master prompt. Menumpuk semua sekaligus di atas inti sesi+TV yang belum teruji
  menaikkan risiko bug billing secara signifikan (ledger, webhook, isolasi tenant
  masing-masing subsistem besar sendiri).
  *Trade-off yang ditolak:* "bangun semua sekaligus" — ditolak karena kompleksitas
  ditumpuk sebelum inti billing terbukti benar di lapangan.
  *V2 backlog:* akun pelanggan + saldo tanpa expiry, Midtrans/payment gateway,
  notifikasi WhatsApp, engine diskon/promo, multi-outlet penuh (switching + isolasi
  data + laporan gabungan).

- **Fondasi multi-outlet: tabel `outlets` + kolom `outlet_id` ditambahkan di V1**
  (users, unit_types, units), tanpa UI/logic ganti-outlet apa pun.
  *Why:* user mengonfirmasi multi-outlet sebagai kebutuhan nyata dekat, bukan
  spekulasi. Menambah kolom sekarang membuat migrasi V2 additive, bukan destruktif
  (tidak perlu backfill/split data existing).
  *Trade-off yang ditolak:* tunda total ke V2 — ditolak karena migrasi backfill
  outlet_id ke data produksi yang sudah berjalan jauh lebih berisiko daripada
  menambah kolom kosong di awal.

- **Pembayaran V1: kasir mencatat manual, QRIS ditampilkan statis** (bukan
  generate dinamis via gateway).
  *Why:* payment gateway online adalah non-goal v1 eksplisit; kasir tetap perlu
  jalur QRIS/transfer untuk pelanggan yang tidak bawa cash, tanpa membangun
  integrasi gateway yang belum diperlukan.

## Fase 1 — Fondasi

- **MySQL 8.4 (bukan 9.x) dipasang via Homebrew, keg-only di port 3307**,
  terpisah dari MariaDB yang sudah berjalan di port 3306 milik project lain.
  *Why:* mesin dev hanya punya MariaDB; stack terkunci ke MySQL 8, dan constraint
  kritikal di `rental_sessions` (generated column + unique index) memakai sintaks
  MySQL yang perlu diverifikasi terhadap mesin asli, bukan diasumsikan kompatibel
  dengan MariaDB. `mysql@8.4` dipilih (bukan `mysql`/9.x terbaru) karena itu rilis
  LTS yang paling dekat dengan "MySQL 8" yang dikunci di stack. Instalasi tidak
  di-link ke `/opt/homebrew/bin` (keg-only) sehingga tidak menimpa binary MariaDB
  yang sudah dipakai project lain di mesin ini.

- **Test suite (Pest) berjalan terhadap MySQL 8.4 asli** (database
  `creative_trees_billing_test`), **bukan SQLite in-memory** (default Laravel).
  *Why:* test konkurensi wajib di §10 (`Unit::lockForUpdate()`, dua request
  paralel membuka unit sama) butuh row-level locking sungguhan — SQLite mengunci
  seluruh file, bukan per baris, sehingga test konkurensi di atasnya memberi
  keyakinan palsu. Constraint `uq_active_unit` juga sintaks MySQL-spesifik.

- **Constraint kritikal `active_unit_id` ditulis via Blueprint fluent
  (`storedAs()` + `unique()`)**, bukan raw SQL seperti draf awal di §4.
  *Why:* diverifikasi langsung dari source `vendor/laravel/framework` (Laravel
  13.20 terinstal) bahwa `storedAs()` menghasilkan DDL `GENERATED ALWAYS AS (...)
  STORED` yang identik secara semantik. Diuji empiris dua kali: (1) raw SQL
  langsung ke MySQL — insert sesi aktif kedua di unit yang sama gagal dengan
  `Duplicate entry ... for key 'uq_active_unit'`, sesi `completed` melepas
  slotnya (active_unit_id kembali NULL); (2) lewat Pest + RefreshDatabase di
  `tests/Feature/DatabaseSetupTest.php`, hasil sama.

- **PHP backed enum untuk semua kolom bertipe enum di DB** (role, control_driver,
  power_state, session type/status, payment_method, alert type/status), dengan
  Eloquent cast langsung ke enum tersebut.
  *Why:* diverifikasi query builder Laravel 13 (`enum_value()` di
  `Query/Builder.php`) meng-unwrap backed enum otomatis di `where()`, jadi enum
  bisa dipakai langsung tanpa `->value` manual. Filament v5 juga native mendukung
  PHP enum di form/table (dipakai mulai Fase 3). Ditaruh di
  `app/Domain/{Billing,Sessions,Devices}` sesuai struktur §12, kecuali `UserRole`
  yang ditaruh di `app/Models` karena lintas-domain (auth), bukan milik satu
  domain bisnis tertentu.

- **`units.code` unique di-scope per outlet** (`unique(['outlet_id','code'])`),
  bukan unique global seperti draf awal §4.
  *Why:* mengikuti keputusan fondasi multi-outlet — tetap benar untuk V1 (satu
  outlet, jadi efektif unique global juga) tapi tidak perlu migrasi ulang saat V2.

## Fase 2 — Domain

- **Paket dibayar di muka (saat mulai), open play dibayar di akhir (saat stop).**
  *Why:* ini default yang eksplisit ditulis di §5 master prompt sendiri ("base_amount
  = price, default dibayar di muka") dan tidak pernah dibantah di jawaban Fase 0 —
  bukan tebakan API, tapi mengikuti default yang sudah didokumentasikan. `StartSessionAction`
  mewajibkan `paymentMethod` untuk tipe paket, `CompleteSessionAction` mewajibkannya
  untuk tipe open play (kecuali sudah terisi dari paket).

- **Interface `TvControl` + `DeviceManager` + `ManualDriver` dibangun di Fase 2**,
  bukan ditunda penuh ke Fase 5.
  *Why:* `StartSessionAction`/`CompleteSessionAction` secara eksplisit diwajibkan
  §5 memerintah TV on/off — tidak bisa jadi placeholder kosong (dilarang §0.4).
  `HomeAssistantDriver`/`TasmotaDriver` tetap ditunda ke Fase 5 sungguhan;
  `DeviceManager::driverFor()` melempar `RuntimeException` eksplisit untuk
  keduanya sampai diimplementasi — bukan pura-pura berhasil.

- **Perintah device dibungkus `DeviceManager::attempt()`**: exception apa pun dari
  driver ditangkap, dicatat sebagai log terstruktur, dan tidak pernah menggagalkan
  transaksi billing yang memanggilnya.
  *Why:* prinsip arsitektur #1 (§3) — "state billing tidak pernah bergantung pada
  state device". TV yang gagal nyala/mati tidak boleh membuat sesi gagal dibuka/ditutup.

- **`ManualDriver::powerOff()` langsung membuat `device_alert` (power_off_failed)**,
  tidak melalui reconciliation loop generik yang direncanakan di Fase 5.
  *Why:* reconciliation loop generik memverifikasi lewat `state()` setelah 10 detik —
  tapi `ManualDriver::state()` selalu `Unknown`, tidak pernah `On`, jadi tidak akan
  pernah memicu alert lewat jalur itu. Manual pada dasarnya tidak bisa diverifikasi
  sama sekali, jadi alert dibuat langsung saat itu juga.

- **`QUEUE_CONNECTION` test diubah ke `database`** (dari default Laravel `sync`).
  *Why:* dengan `sync`, `->delay()` diabaikan dan job jalan seketika saat
  di-dispatch — sesi paket yang baru dibuat akan langsung "expired" oleh
  `ExpireRentalSession` di tengah test, menutupi bug alih-alih mengujinya. Dengan
  `database`, job betul-betul mengendap di tabel `jobs` sampai `available_at`
  tercapai, sama seperti production.

- **Test konkurensi asli** (`tests/Concurrency/StartSessionConcurrencyTest.php`)
  memakai `Illuminate\Support\Facades\Process::pool()` menjalankan dua proses OS
  sungguhan (`php artisan testing:attempt-start-session`) hampir bersamaan, lalu
  `DatabaseTruncation` (bukan `RefreshDatabase`) untuk suite `tests/Concurrency`.
  *Why:* `RefreshDatabase` membungkus tiap test dalam transaksi yang di-rollback —
  data itu tidak pernah commit, jadi proses child (koneksi DB terpisah) tidak akan
  pernah melihatnya. `DatabaseTruncation` benar-benar commit data lalu truncate
  sebelum test berikutnya, cocok untuk skenario lintas-proses. Dijalankan 5x
  berturut-turut untuk memastikan tidak flaky — semua lolos, tepat satu sesi aktif
  per percobaan.
  *Trade-off yang ditolak:* membuktikan invariant hanya lewat urutan sekuensial
  (buka dua kali berurutan, assert yang kedua gagal) — ditolak karena tidak
  membuktikan row lock (`lockForUpdate()`) benar-benar menyerialkan request
  paralel sungguhan, hanya membuktikan constraint DB menolak duplikat.

- **Void bisa dilakukan dari status `active` maupun `completed`**, hanya ditolak
  dari `voided` (mencegah void ganda).
  *Why:* §5 rule 5 hanya bilang "void: hanya owner, wajib alasan" tanpa membatasi
  status asal — dan use case nyata owner membatalkan transaksi yang SUDAH selesai
  (salah catat pembayaran, dsb) sama validnya dengan membatalkan sesi yang masih
  aktif. Kalau sesi masih aktif saat di-void, TV tetap diperintah mati.

## Fase 3 — Panel Filament

- **Dashboard kasir dibangun sebagai `TableWidget` native di atas `Filament\Pages\Dashboard`
  bawaan**, bukan custom Page dengan Blade tangan.
  *Why:* diminta eksplisit untuk full-native Filament. `contentGrid()` + layout
  `Split`/`Stack` merender grid kartu unit; empat aksi sesi (Mulai/Perpanjang/
  Stop & Bayar/Nyalakan-Matikan TV) jadi native record action — Filament yang
  urus authorize, loading state, dan modal wiring, bukan `wire:click` manual.
  Diverifikasi ke dokumentasi resmi Filament (contentGrid/Split/Stack,
  TableWidget) via WebFetch, bukan ditebak dari memori.
  *Trade-off yang ditolak:* custom `Filament\Pages\Page` dengan grid Blade tangan
  (draf awal) — ditolak setelah diminta karena kurang "native", dan ternyata
  memang lebih rapuh (wire:click manual, tidak dapat authorize/loading state
  bawaan Filament).

- **Dua bug nyata ditemukan lewat browser sungguhan, bukan cuma test otomatis:**
  1. Closure `visible()`/`formatStateUsing()` di level KOLOM (bukan action)
     ternyata dipanggil sekali tanpa konteks record (structural check), jadi
     parameter `Unit $record` yang strict-typed crash dengan TypeError. Semua
     closure yang menerima `$record` di kolom dibuat nullable (`?Unit $record`).
  2. `Select::options(PaymentMethod::class)` (native enum support Filament)
     sudah mengembalikan instance enum langsung ke `$data`, bukan string —
     memanggil `PaymentMethod::from()` lagi di atasnya melempar TypeError.
     Ditambahkan `resolvePaymentMethod()` yang menerima instance ATAU scalar.
  *Why dicatat:* bukti bahwa "test hijau" tidak cukup untuk fitur UI — dua bug
  ini lolos dari test otomatis (yang memanggil Action lewat kode langsung,
  bukan lewat siklus render Livewire sungguhan) dan hanya ketahuan setelah
  benar-benar diklik di browser. Alur penuh (mulai → modal → submit → kartu
  ter-update → stop & bayar → total benar → riwayat sesi tercatat) diverifikasi
  end-to-end, termasuk policy: nav ter-filter per role, dan akses langsung ke
  URL owner-only dari akun kasir memberi 403 sungguhan (bukan cuma nav tersembunyi).

- **`RentalSessionResource` & `DeviceAlertResource` tidak punya halaman
  Create/Edit.** Sesi hanya bermutasi lewat action domain (Fase 2); alert hanya
  dibuat sistem lewat driver. Form edit mentah akan melewati kalkulasi billing
  dan activity log — sengaja tidak disediakan, bukan kelalaian.

- **`SettingResource` tanpa Create/Delete**, hanya Edit, memetakan `value.minutes`
  langsung (dot-notation form state Filament) supaya owner mengedit angka polos,
  bukan JSON mentah.

## Fase 4 — Realtime

- **`config/filament.php` broadcasting.echo pakai `broadcaster: 'reverb'`**,
  bukan `'pusher'` (yang tertulis di komentar default Filament).
  *Why:* dicek langsung ke bundle `public/js/filament/filament/echo.js` yang
  ter-publish — string `reverb` memang ada di dalamnya (laravel-echo versi
  terbundle sudah punya broadcaster type khusus Reverb), jadi tidak perlu
  menyamar sebagai Pusher. Key/host/port dibaca dari `VITE_REVERB_*` yang
  sudah ada di `.env` sejak `reverb:install` di Fase 1.

- **Semua event broadcast diberi `broadcastAs()` eksplisit** (`session.started`,
  `session.extended`, `session.ending`, `session.ended`, `device-alert.raised`)
  alih-alih memakai default Laravel (nama kelas lengkap dengan namespace).
  *Why:* listener Livewire `#[On('echo-private:panel.units,.nama')]` jauh
  lebih terbaca dengan nama pendek dibanding menyisipkan
  `App\Domain\Sessions\Events\SessionStarted` mentah-mentah di string atribut.

- **Verifikasi realtime push dilakukan lewat inspeksi DOM/Alpine state langsung
  (`javascript_tool`), bukan screenshot atau `get_page_text`.**
  *Why:* screenshot terbukti tidak bisa diandalkan untuk widget ini di bawah
  browser automation (menangkap frame sebelum paint selesai, berulang kali
  menunjukkan "kosong" padahal kontennya sudah benar) — dikonfirmasi dengan
  `document.body.innerHTML`/`get_page_text` menunjukkan data yang benar di
  saat yang sama screenshot kosong. `get_page_text` sendiri scoped ke
  `<main>`, jadi tidak pernah menangkap modal Filament (yang di-teleport ke
  luar `<main>`) — modal harus dicek lewat `document.querySelector('.fi-modal')`
  langsung. Dicatat supaya sesi debugging berikutnya tidak mengulang jalan
  buntu yang sama.

- **`UnitGridWidget::$isLazy = false`** (lazy loading widget dimatikan).
  *Why:* widget ini ADALAH konten utama halaman dashboard, bukan widget
  sekunder di halaman yang sudah padat — jadi lazy loading tidak membeli
  apa-apa selain round-trip tambahan. Selama sesi testing browser yang
  panjang, mekanisme mount lazy-load (`x-init="$wire.__lazyLoad(...)"`)
  terbukti jadi sumber utama widget macet di "Loading..." tanpa error PHP
  maupun JS apa pun — mematikannya menghilangkan kelas masalah itu sepenuhnya
  untuk widget yang toh tidak butuh optimisasi ini.

- **Countdown (`countdown`) & count-up (`countup`) Alpine components
  didaftarkan lewat render hook `PanelsRenderHook::BODY_END`** (script inline
  di `AdminPanelProvider`), bukan file JS terpisah + build step.
  *Why:* dua komponen kecil, murni tampilan (§3: server tetap satu-satunya
  sumber kebenaran durasi), tidak butuh dependency baru atau langkah build
  Vite tambahan — Alpine sudah bawaan Livewire. Logika `tick()` diverifikasi
  benar dengan memanggilnya manual lewat `Alpine.$data(el).tick()` dan
  membandingkan hasilnya ke elapsed time sungguhan dari `new Date() -
  startedAt`, bukan hanya dari tampilan visual (yang under browser automation
  kena throttling `setInterval` background-tab dari Chrome — bukan bug kode,
  dikonfirmasi lewat perbandingan manual di atas).

- **File korup sementara akibat kehilangan izin filesystem OS di tengah sesi**
  (folder `Documents` sempat jadi "Operation not permitted" untuk semua tool,
  lalu pulih dengan beberapa file ter-revert ke versi lama). Semua file yang
  kena di-restore dari commit terakhir via `git checkout`, lalu perubahan
  Fase 4 (broadcastAs, config echo, listener) ditulis ulang dan **diverifikasi
  persisten** (baca ulang setelah tiap tulis) sebelum commit berikutnya.
  *Why dicatat:* commit sesering mungkin per unit kerja logis bukan cuma soal
  kerapian riwayat git — di sesi ini itu yang menyelamatkan pekerjaan Fase 3
  saat filesystem sempat tidak stabil.

## Fase 5 — Devices

- **API `php-mqtt/client` dan REST Home Assistant diverifikasi langsung dari
  source package terpasang & dokumentasi resmi HA sebelum menulis driver**
  (bukan diingat/ditebak) — `MqttClient`/`ConnectionSettings` dari
  `vendor/php-mqtt/client/src/`, endpoint `/api/services/<domain>/<service>`
  dan `/api/states/<entity_id>` dari developers.home-assistant.io.
  *Why:* aturan eksplisit "dilarang mengarang API" — device control adalah
  satu-satunya lapisan di sistem ini yang bicara ke hardware pihak ketiga.

- **`HomeAssistantDriver` mengontrol TV lewat domain `media_player`**, bukan
  domain per-merk (`androidtv`, `webostv`, dst).
  *Why:* HA mengabstraksikan semua integrasi TV ke entity `media_player.*`
  yang seragam — satu driver bekerja untuk Sony/TCL/Coocaa Android TV tanpa
  cabang per-merk, sesuai jawaban Fase 0 (keluarga Android TV, bukan satu merk).

- **`TasmotaDriver::state()` TIDAK query MQTT secara sinkron** — ia hanya
  membaca `power_state`/`last_seen_at` yang di-cache di DB oleh daemon
  `bridge:mqtt-listen`, dengan staleness check 120 detik (kembali
  `PowerState::Unknown` kalau lewat itu).
  *Why:* MQTT itu protokol push/subscribe, bukan request/response — memaksa
  query sinkron berarti connect+subscribe+tunggu tiap kali `state()`
  dipanggil, lambat dan rawan timeout. Bridge daemon yang selalu terhubung
  jauh lebih murah dan matching cara kerja natural MQTT.

- **`TasmotaTopic` diekstrak jadi value object kecil terpisah** (`command()`,
  `power()`, `availability()`, `controlRefFrom()`), dipakai baik oleh
  `TasmotaDriver::publish()` maupun `MqttBridgeListen`.
  *Why:* dua sisi (publish command, subscribe status) harus sepakat persis
  soal format topic `{prefix}/<control_ref>/{POWER|LWT}` — kalau format ini
  di-inline terpisah di dua file, keduanya bisa drift diam-diam. Testable
  langsung tanpa broker sungguhan (`tests/Unit/Domain/Devices/TasmotaTopicTest.php`).

- **`DeviceManager::powerOff()` jadi satu-satunya jalur power-off**, membungkus
  `attempt()` + otomatis men-dispatch `VerifyUnitPoweredOffJob` dengan delay
  10 detik. Tiga caller lama (`CompleteSessionAction`, `VoidSessionAction`,
  `UnitGridWidget` toggle) diarahkan ke method ini.
  *Why:* reconciliation ("TV masih menyala setelah perintah off?") harus
  berlaku di SEMUA jalur power-off, bukan cuma satu — memusatkannya di satu
  method mencegah satu jalur baru lupa didaftarkan nanti. Billing tetap tidak
  pernah menunggu hasil verifikasi ini (job jalan belakangan lewat queue).

- **`units:poll-state` dijadwalkan `everyThirtySeconds()`, bukan tiap 45
  detik seperti disebut di §7.** Laravel scheduler hanya punya preset
  kelipatan 5/10/15/20/30 detik untuk sub-minute tasks, tidak ada
  `everyFortyFiveSeconds()` bawaan dan menulis modulo-detik custom lewat
  `->when()` untuk selisih 15 detik ini over-engineered untuk polling
  fallback yang toh tidak pernah mempengaruhi billing (prinsip arsitektur
  #1). 30 detik dipilih (lebih sering, bukan lebih jarang) supaya tetap
  dalam anggaran "state device stale secepatnya terlihat".

- **`units:poll-state` hanya query unit ber-`control_driver=home_assistant`**,
  tidak menyentuh unit Tasmota sama sekali.
  *Why:* Tasmota sudah dapat push realtime lewat MQTT LWT/POWER
  (`bridge:mqtt-listen`) — polling ulang lewat cara lain cuma kerja ganda
  tanpa manfaat, dan HA memang tidak punya mekanisme push ke aplikasi ini
  sehingga satu-satunya cara tahu perubahan state-nya adalah tanya berkala.

- **`units:poll-state` & `bridge:mqtt-listen` hanya menulis DB dan
  broadcast `UnitPowerStateChanged` kalau state benar-benar berubah**
  (bukan tiap siklus/pesan).
  *Why:* mencegah membanjiri dashboard kasir dengan re-render kosong tiap 30
  detik atau tiap heartbeat MQTT untuk unit yang state-nya memang tidak berubah.

- **`bridge:mqtt-listen` pakai backoff manual di outer `while(true)`**
  (1s → 2s → ... → cap 30s) di atas `ConnectionSettings` bawaan library,
  bukan cuma mengandalkan `setReconnectAutomatically()` milik php-mqtt/client.
  *Why:* reconnect bawaan library membatasi jumlah percobaan
  (`setMaxReconnectAttempts`) lalu menyerah — daemon yang dikelola Supervisor
  ini harus tetap mencoba selamanya (broker Mosquitto down semalaman untuk
  maintenance, misalnya), jadi loop terluar sendiri yang jadi jaring pengaman
  akhir.

- **`WakeOnLan` ditulis pakai ekstensi `ext-sockets` (`socket_create`, bukan
  `stream_socket_client`)**, dipanggil best-effort dari
  `HomeAssistantDriver::powerOn()` kalau `Unit::tv_mac` terisi.
  *Why:* magic packet WoL butuh broadcast UDP (`SO_BROADCAST`), yang tidak
  bisa diset lewat stream context PHP — ini satu-satunya cara standar
  kirim broadcast UDP dari PHP. `ext-sockets` adalah ekstensi inti PHP
  (bukan dependency Composer baru), tersedia default di hampir semua image
  PHP termasuk yang dipakai proyek ini. Diuji tanpa broadcast sungguhan:
  test membuka UDP listener di `127.0.0.1` dan memverifikasi struktur byte
  paket (6× `0xFF` + 16× MAC 6-byte = 102 byte) yang benar-benar diterima.

- **`docker-compose.devices.yml` dipisah dari deploy aplikasi utama**
  (`deploy/`, dikerjakan Fase 6), versi image di-pin eksplisit
  (`homeassistant/home-assistant:2026.7.2`, `eclipse-mosquitto:2.1.2-alpine`
  — dicek langsung ke Docker Hub, bukan `latest`).
  *Why:* §2 mengunci versi semua komponen; `latest` akan diam-diam berubah
  di balik layar tiap kali container di-pull ulang. Dipisah dari deploy app
  utama karena siklus hidupnya beda — HA/Mosquitto adalah infrastruktur
  device per-outlet, bukan bagian dari deployment kode aplikasi.

- **Home Assistant pakai `network_mode: host`.**
  *Why:* device discovery TV Android (mDNS/SSDP) butuh HA berada di L2
  network yang sama, tidak bisa lewat NAT container biasa. Konsekuensi:
  compose ini hanya jalan di Docker Engine Linux (target production), TIDAK
  di Docker Desktop macOS/Windows — dicatat jelas di komentar file & di sini
  supaya tidak membingungkan saat dev lokal di macOS (jalankan unit dengan
  `control_driver=manual` saja untuk dev, seperti sudah didukung sejak Fase 2).

- **Mosquitto `allow_anonymous false` + `password_file` + `acl_file` aktif
  sejak awal** (bukan default anonymous seperti draft awal fase ini — dikoreksi
  di Fase 6 setelah dicek ulang ke §11 yang eksplisit minta "anonymous off").
  *Why:* generate isi password file (`mosquitto_passwd`) tetap tugas manusia
  sekali-jalan per outlet (§14) — tapi *wiring*-nya (config menunjuk ke file
  itu, container menolak start tanpanya) adalah kode, bukan langkah manual,
  dan harus benar dari awal. Fail-secure (§3.5): tanpa file password, broker
  menolak start sama sekali alih-alih diam-diam menerima siapa saja.

## Fase 6 — Hardening & serah terima

- **Dua celah ditemukan lewat audit checklist §10 satu-per-satu** (bukan
  ditulis ulang dari draft Fase 5 tanpa dicek):
  1. `DeviceAlertType::DeviceOffline` sudah ada di enum sejak Fase 1 tapi
     TIDAK PERNAH dibuat di mana pun — `units:poll-state` dan
     `bridge:mqtt-listen` cuma broadcast `UnitPowerStateChanged`, tidak
     pernah membuat `device_alert`. Padahal DoD §10 eksplisit: "Unit dibuat
     unreachable → ≤ 90 detik badge berubah + `device_alert` muncul".
  2. `VerifyUnitPoweredOffJob` (reconciliation loop) memakai
     `DeviceAlertType::StateMismatch`, padahal §7 eksplisit menulis "buat
     device_alert `power_off_failed`" untuk skenario TV masih menyala 10
     detik setelah perintah off — dan DoD §10 juga eksplisit menyebut
     `power_off_failed`, bukan `state_mismatch`.

  *Why keduanya diperbaiki, bukan dibiarkan:* checklist DoD bukan formalitas
  — dua baris ini adalah bukti nyata kenapa audit satu-per-satu (bukan
  "kelihatannya sudah beres") ada gunanya. Diperbaiki dengan menambah
  `DeviceManager::reportState(Unit, PowerState)` sebagai titik masuk
  TUNGGAL untuk `units:poll-state` maupun `bridge:mqtt-listen` melaporkan
  state yang mereka amati — state berubah → broadcast; kalau state baru
  `Unreachable` → tambahan buat `device_alert` tipe `DeviceOffline`. Kedua
  caller lama yang masing-masing menduplikasi logika "kalau berubah, tulis
  DB + broadcast" diarahkan ke method ini, supaya perilaku ini konsisten
  dari kedua sumber sekaligus dan tidak bisa diam-diam drift lagi.
  `DeviceAlertType::StateMismatch` sekarang jadi nilai enum yang tersedia di
  schema tapi tidak dipakai V1 — bukan dihapus (tetap valid sebagai nilai
  kolom), hanya tidak ada jalur kode yang menghasilkannya saat ini.

- **`mysqldump`/`mysql` client HARUS dari vendor yang sama dengan server**
  (MySQL client untuk MySQL server) — ditemukan lewat uji restore
  sungguhan (bukan cuma menulis script lalu percaya), bukan diasumsikan.
  *Why:* mesin dev ini punya MariaDB client di PATH (`mysqldump`/`mysql`
  default resolve ke situ) SEKALIGUS MySQL 8.4 (Homebrew, keg-only, dipakai
  aplikasi). Dump lewat client MariaDB terhadap tabel `rental_sessions`
  (yang punya kolom generated `active_unit_id`) menulis `NULL` eksplisit di
  posisi kolom itu di setiap `INSERT`; MySQL 8 lalu MENOLAK restore-nya
  (`ERROR 3105`, generated column tidak boleh diberi nilai eksplisit sama
  sekali, walau `NULL`). Diverifikasi ulang pakai `mysqldump`/`mysql` dari
  `/opt/homebrew/opt/mysql@8.4/bin` (vendor yang sama dengan server) →
  restore sukses, jumlah baris `users`/`units`/`rental_sessions`/
  `device_alerts` identik sebelum & sesudah. Di server production (Ubuntu +
  paket `mysql-server` dari APT), client bawaan sudah otomatis satu vendor
  — potensi masalah ini spesifik ke mesin dev bertoolchain campuran, dicatat
  di `RUNBOOK.md` supaya tidak mengejutkan siapa pun yang menguji ulang di
  environment serupa.

- **`grep -riE "token|password" storage/logs` (DoD §10) sempat menemukan
  SATU baris** — bukan HA_TOKEN/kredensial MQTT (yang memang jadi fokus
  §9.4 dan sudah dijamin oleh `tests/Feature/Security/NoSecretsInLogsTest.php`),
  melainkan hash bcrypt kolom `users.password` yang ikut ter-log utuh di
  pesan `QueryException` bawaan Laravel untuk error duplicate-key lama
  (SQL statement dengan bindings-nya di-stringify penuh oleh framework saat
  exception, bukan oleh kode aplikasi ini).
  *Why dicatat, bukan "diperbaiki":* ini perilaku default Laravel
  (`Illuminate\Database\QueryException`) yang menyertakan bound SQL lengkap
  di pesan exception untuk keperluan debugging — bukan celah spesifik
  proyek ini, dan nilainya adalah HASH satu-arah (bcrypt), bukan password
  plaintext. Beda kelas risiko dari HA_TOKEN/kredensial MQTT mentah yang
  memang §9.4 khawatirkan. Log lama dibersihkan (`storage/logs/laravel.log`
  di-truncate); grep diulang setelah full test suite (117 test, termasuk
  jalur device-failure yang sengaja memicu `Log::warning`) dan hasilnya
  nihil — dibuktikan bersih untuk operasi normal aplikasi saat ini.

- **`deploy/` (nginx vhost, 4 config Supervisor, backup+restore script,
  crontab) ditulis mengikuti pola resmi dari dokumentasi Laravel/Reverb**
  (`search-docs`), bukan ditebak — nginx vhost persis contoh resmi
  (`fastcgi_pass unix:...`, blok deny dotfile), Supervisor `queue:work`
  persis pola resmi (`numprocs`, `stopasgroup`/`killasgroup`,
  `stopwaitsecs`) dengan `numprocs` diturunkan ke 2 (bukan 8 di contoh) —
  beban job sistem ini (billing satu outlet) jauh di bawah skala yang
  contoh itu diasumsikan.

- **Backup script pakai `--defaults-extra-file` (temp file mode 600,
  dihapus via `trap`), bukan `--password=` di argumen CLI mysqldump.**
  *Why:* `--password=` di argumen command line terlihat penuh oleh siapa
  pun yang menjalankan `ps aux` selama dump berjalan — pelanggaran §9.4
  yang gampang dihindari dengan pola standar ini.

## Audit kelengkapan v1 (pasca Fase 6)

Diminta eksplisit oleh user: cek ulang apakah v1 benar-benar lengkap & profesional. Diaudit satu per satu ke §8/§9/§12, bukan diasumsikan sudah beres karena checklist §10 sudah lolos — ditemukan satu celah signifikan dan satu celah kecil:

- **`Filament\Pages\SalesReport` (Laporan) ditambahkan — §8 secara eksplisit
  memintanya ("Laporan (owner-only): rekap harian & bulanan — jumlah sesi,
  pendapatan per metode bayar & per tipe unit, jam sibuk. Export CSV.") dan
  sebelumnya TIDAK ADA sama sekali** — bukan variasi/penyempurnaan dari
  sesuatu yang sudah ada, murni ketinggalan dari Fase 3.
  *Why terlewat sebelumnya:* checklist DoD §10 tidak eksplisit menyebut
  "laporan" sebagai item terpisah (hanya §8 yang memintanya), jadi audit
  checklist §10 satu-per-satu di Fase 6 tidak menangkapnya — pelajarannya:
  audit ulang harus menyisir SEMUA bagian spek (§8 juga), bukan hanya
  checklist §10 yang eksplisit.
  *Desain:* satu custom Page (bukan Resource — tidak ada CRUD, murni
  agregat baca), rentang tanggal bebas (bukan toggle harian/bulanan
  terpisah) supaya satu halaman melayani rekap sehari maupun sebulan
  penuh; breakdown per hari di tabel bawah tetap memberi rincian harian
  walau rentang dipilih sebulan. Native Filament penuh: `form(Schema)` +
  `$this->form` (pola persis `Filament\Auth\Pages\EditProfile` bawaan,
  dibaca langsung dari source sebelum menulis kode), `HasTable` +
  `InteractsWithTable` dengan `->records()` "custom data" (data agregat
  array, bukan Eloquent model) untuk breakdown harian, `<x-filament::section>`
  untuk grouping visual di Blade tipis yang cuma merender `$this->form`/
  `$this->table` + angka ringkasan.
  *CSV export* lewat `response()->streamDownload()` dikembalikan langsung
  dari closure `Action::action()` — dikonfirmasi ini pola resmi Livewire
  ("Streaming downloads"), bukan lewat sistem `ExportAction`/Exporter
  bawaan Filament (itu untuk export baris tabel mentah + queue job,
  overkill untuk satu file rekap sinkron kecil).
  *Diverifikasi bukan cuma ditulis:* dimuat di browser sungguhan (menemukan
  1 bug nyata — closure `records()` di-type-hint `Eloquent\Collection`
  padahal `groupBy()->map()` di atas Eloquent Collection menghasilkan
  `Support\Collection`, `TypeError` langsung muncul di halaman — diperbaiki
  dengan alias import terpisah), filter tanggal reaktif dicoba manual,
  export CSV dicoba dari UI. Selain itu ditambah test otomatis:
  `assertSee`/`assertSeeInOrder` untuk angka agregat dengan fixture
  terkontrol (bukan data seeder acak), dan test export CSV yang
  membandingkan BYTE PERSIS keluaran lewat `assertFileDownloaded($nama,
  $contentPersis)` — bukan cuma "file terunduh".

- **`Model::preventLazyLoading()` ditambahkan ke `AppServiceProvider::boot()`,
  aktif non-production** — §12 eksplisit memintanya sebagai bukti tabel
  Filament sudah eager-load relasinya, sebelumnya tidak pernah diset sama
  sekali (nilai defaultnya di Laravel adalah OFF).
  *Why aman diaktifkan belakangan:* full test suite (122 test) tetap hijau
  tanpa perubahan apa pun begitu diaktifkan — membuktikan semua resource/
  widget yang sudah ada memang sudah eager-load dengan benar sejak awal
  (`UnitGridWidget`, `SalesReport`, dst). Diverifikasi ulang lewat browser
  sungguhan sebagai login kasir DAN owner (dashboard, modal Mulai Sesi
  dengan tipe Paket yang me-load relasi `Package`, resource Unit/Alert,
  halaman Laporan) — tidak ada `LazyLoadingViolationException` di manapun.

- **`Rupiah::format()` diekstrak dari method statis privat di
  `UnitGridWidget`** ke `app/Domain/Billing/Rupiah.php`, dipakai ulang oleh
  `SalesReport`.
  *Why:* begitu ada caller kedua yang genuinely butuh format rupiah yang
  sama, method itu bukan lagi detail privat satu widget — memindahkannya
  ke domain Billing (tempat `OpenPlayBillingCalculator` juga tinggal)
  mencegah drift format kalau salah satu sisi diubah sendiri-sendiri nanti.

- **Rate limit login (§9.5) TIDAK butuh kode tambahan** — dicek langsung ke
  `vendor/filament/filament/src/Auth/Pages/Login.php`: sudah pakai
  `Illuminate\Support\Facades\RateLimiter` bawaan (maks 5 percobaan) sejak
  instalasi awal. Dicatat di sini eksplisit supaya audit berikutnya tidak
  menganggap ini celah yang belum dikerjakan.

- **`SESSION_SECURE_COOKIE` sengaja dibiarkan TIDAK diisi di `.env`/`.env.example`
  (bukan celah)** — §9.5 minta "secure mengikuti environment", dan
  `config/session.php` sudah `env('SESSION_SECURE_COOKIE')` tanpa default
  hardcoded: kosong berarti Laravel mengikuti skema request sesungguhnya
  (adaptif). Memaksa `true` justru akan MEMATIKAN cookie session di
  deployment default proyek ini (HTTP polos, LAN-only, tanpa SSL — lihat
  `RUNBOOK.md` "Akses remote"). Dicatat eksplisit dengan alasan yang sama
  seperti poin di atas.

## Audit kelengkapan v1, ronde 2 — celah integritas billing

Diminta lagi oleh user untuk mengecek ulang. Kali ini fokus spesifik ke
peringatan §8 yang belum pernah diverifikasi eksplisit: *"aman terhadap
Livewire state tampering — jangan percaya property Livewire sebagai fakta;
validasi & authorize ulang server-side di setiap aksi."* Ditelusuri ke
empat action di `UnitGridWidget` satu per satu.

- **`StartSessionAction` tidak pernah memvalidasi `$package->unit_type_id`
  cocok dengan `$unit->unit_type_id` — DITEMUKAN & DIPERBAIKI.**
  *Skenario nyata:* dropdown "Paket" di modal Mulai Sesi sudah difilter ke
  paket milik tipe unit yang benar (`Package::query()->where('unit_type_id',
  $record?->unit_type_id)`), tapi itu FILTER UI, bukan validasi server.
  Request Livewire yang di-tamper (atau bug UI state yang stale) bisa
  mengirim `package_id` milik tipe unit LAIN — action-nya menerima begitu
  saja dan menulis `base_amount = $package->price`, yaitu **harga yang
  salah** untuk unit itu. Ini persis kelas bug yang diperingatkan §8, dan
  soal duit — kategori paling sensitif di sistem ini.
  *Fix:* satu guard clause di awal `StartSessionAction::handle()`, menolak
  dengan `InvalidArgumentException` kalau `$package->unit_type_id !==
  $unit->unit_type_id`. Test baru membuktikan reject-nya nyata: memanggil
  action langsung dengan paket dari `unit_type` lain (bukan cuma lewat UI
  yang toh sudah menyaring), meniru persis skenario "state Livewire yang
  ditamper" yang tidak lewat filter dropdown sama sekali.
  *Kenapa tidak ketahuan di audit-audit sebelumnya:* checklist DoD §10
  tidak menyebutnya, dan semua test `StartSessionActionTest` yang sudah ada
  sejak Fase 2 selalu memakai `Package::factory()->for($unit->unitType)` —
  skenario "cocok" doang, tidak ada yang pernah mencoba skenario mismatch.

- **Tiga action lain di `UnitGridWidget` (`extend`, `stop`, `togglePower`)
  DICEK dan sudah aman** — tidak perlu diubah, dicatat supaya tidak dicek
  ulang tanpa perlu di audit berikutnya:
  - `extend`: `$record->activeSession` diresolusi server-side dari relasi
    Eloquent (bukan ID sesi kiriman klien) — kasir tidak bisa memperpanjang
    sesi unit lain lewat ID tebakan. `amount` memang input manual kasir per
    desain §5.3 (bukan nilai turunan yang bisa "dipalsukan" karena tidak
    ada nilai benar yang dilewati).
  - `stop`: total tagihan yang ditampilkan di modal murni display
    (`estimasi_total`), TIDAK pernah dikirim balik ke server — total
    sungguhan selalu dihitung ulang di `CompleteSessionAction` dari
    `started_at`/tarif/increment saat itu juga.
  - `togglePower`: tidak ada perbedaan hak akses kasir/owner untuk
    power on/off manual (§8 mendaftarkannya sebagai aksi kasir juga), jadi
    tidak butuh `->authorize()` berbasis role.
  - Action `void` (satu-satunya yang OWNER-ONLY di antara semua aksi sesi)
    sudah benar memakai `->authorize(fn ($record) => auth()->user()->can('void',
    $record))` di `RentalSessionsTable`, dan sama sekali tidak ada
    jalurnya dari dashboard kasir (`UnitGridWidget`) — dicek eksplisit
    tidak ketemu.

## Full native Filament — menghapus sisa scaffolding Laravel

Diminta user: *"full native Filament, yang sudah ada pada bagian resource
Laravel dihapuskan saja."* Ditelusuri apa saja yang sebenarnya masih non-Filament,
dan ternyata menemukan **satu bug tampilan nyata** yang selama ini tidak terlihat
di test:

- **`resources/views/filament/pages/sales-report.blade.php` DIHAPUS, halaman
  Laporan disusun ulang 100% lewat `content(Schema $schema)`** memakai komponen
  bawaan Filament: `Section`, `Grid`, `TextEntry`, `KeyValueEntry`, `EmbeddedSchema`,
  `EmbeddedTable`.
  *Why — ini bukan sekadar preferensi gaya, Blade lama itu RUSAK secara visual:*
  Blade tersebut memakai kelas Tailwind sendiri (`grid grid-cols-1 sm:grid-cols-3`,
  `flex items-center justify-between`, dst) yang **tidak pernah ter-compile**.
  Satu-satunya entry point Tailwind proyek ini (`resources/css/app.css`) hanya
  dimuat oleh `welcome.blade.php` bawaan Laravel — halaman panel tidak pernah
  memuat bundle Vite sama sekali. Akibatnya ringkasan yang seharusnya 3 kolom
  menumpuk vertikal dan angka tidak rata kanan. Test tetap hijau karena
  `assertSee()` hanya memeriksa teks, bukan layout — persis kelemahan yang
  membuat verifikasi browser sungguhan wajib (dan memang ketahuannya dari
  screenshot, bukan dari test).
  *Hasil setelah pakai komponen native:* ringkasan jadi grid 3 kolom betulan
  dengan label + angka besar (`TextSize::Large`, `FontWeight::Bold`, total
  pendapatan `->color('success')`), dua breakdown jadi tabel key-value native
  bersebelahan, dan **semua empty state jadi bawaan Filament** (`->placeholder()`
  pada entry, `->emptyStateHeading()` pada tabel) — sebelumnya ditulis manual
  pakai `@forelse`. Diverifikasi di browser: rentang tanggal 2030 (tanpa data)
  menampilkan "Belum ada sesi" / "Tidak ada data pada rentang ini." /
  "Tidak ada sesi selesai pada rentang ini" dengan styling yang benar.

- **Vite, npm, Tailwind, dan seluruh toolchain frontend DIHAPUS TOTAL**
  (`package.json`, `package-lock.json`, `vite.config.js`, `resources/css/`,
  `resources/js/`, `node_modules/`).
  *Why:* setelah `welcome.blade.php` hilang, **tidak ada satu pun** file yang
  memakai `@vite` — dicek dengan grep ke seluruh `resources/`, `app/`, dan
  `config/`. Filament v5 mengirim aset CSS/JS-nya sendiri yang sudah
  ter-compile ke `public/` lewat `filament:upgrade` (sudah terpasang di
  `post-autoload-dump`). Menyimpan build step yang tidak menghasilkan apa pun
  hanya menambah dependency, langkah deploy, dan permukaan yang bisa rusak.
  *Risiko yang dicek eksplisit sebelum menghapus:* Echo/Reverb. `config/filament.php`
  membaca `VITE_REVERB_*` lewat `env()` di sisi PHP (prefix `VITE_` cuma nama,
  tidak ada hubungannya dengan Vite), dan Filament punya `echo.js` sendiri di
  bundle-nya. Diverifikasi di browser setelah penghapusan:
  `window.Echo.connector.pusher.connection.state === 'connected'` dengan
  **nol** script Vite di halaman.

- **`resources/views/welcome.blade.php` dihapus; `routes/web.php` jadi
  `Route::redirect('/', '/admin')`.**
  *Why:* aplikasi ini tidak punya halaman publik apa pun — panel adalah
  satu-satunya antarmuka. Redirect (bukan 404) supaya kasir yang mengetik
  alamat server saja tanpa `/admin` tetap sampai ke tempat yang benar.
  Command `inspire` bawaan Laravel di `routes/console.php` ikut dihapus.

- **`composer run dev` tidak lagi memakai `npx concurrently`**, diganti
  `bash -c 'trap "kill 0" EXIT; … & … & wait'`.
  *Why:* `npx concurrently` mengunduh paket dari internet saat dijalankan —
  janggal untuk proyek yang justru baru saja membuang npm sepenuhnya. Pola
  `trap "kill 0" EXIT` + `wait` adalah cara shell standar menjalankan
  beberapa proses dan mematikan semuanya sekaligus saat Ctrl+C, tanpa
  dependency apa pun.
  *Sekalian memperbaiki celah nyata:* skrip lama menjalankan
  server/queue/pail/vite tapi **tidak pernah menjalankan `reverb:start`
  maupun `schedule:work`** — artinya realtime (§6) dan expiry sweep/poller
  (§5, §7) TIDAK aktif saat development lokal, padahal keduanya inti sistem.
  Sekarang keempat proses yang memang dijalankan Supervisor di production
  (lihat `deploy/supervisor/`) juga yang dijalankan di dev, jadi dev dan
  production benar-benar setara.

*Diverifikasi end-to-end di browser setelah semua perubahan di atas:* satu
siklus penuh buka sesi open play → count-up berjalan (00:03 → 00:16) → Stop &
Bayar (metode bayar wajib) → sesi `completed` dengan `total_amount` integer dan
`activity_log` event `completed`. Sekaligus terlihat langsung DoD §10 "unit
unreachable → badge berubah + `device_alert` muncul" bekerja live: unit
ber-driver HA berubah jadi `unreachable` + badge `1 alert` **tanpa reload**,
didorong `units:poll-state` → `DeviceManager::reportState()` → Reverb.

## Polish tampilan — enum berlabel & kartu dashboard

Diminta user untuk memantau sistem berjalan dan membereskan yang "kurang enak
dipandang". Ditelusuri semua halaman panel satu per satu di browser:

- **Semua enum sekarang implement `HasLabel` + `HasColor` bawaan Filament.**
  *Masalahnya:* nilai mentah bocor ke UI di mana-mana — tabel Riwayat Sesi
  menampilkan `open`, `completed`, `cash`; Alert menampilkan `device_offline`;
  Unit menampilkan `home_assistant`. Selain jelek, ini melanggar §8 yang minta
  UI berbahasa Indonesia.
  *Why lewat kontrak enum, bukan `formatStateUsing()` per kolom:* satu
  perubahan di enum otomatis berlaku di SEMUA tempat — badge tabel, filter,
  dropdown `Select::options(PaymentMethod::class)`, sampai form — tanpa perlu
  diingat-ingat di tiap file. Sekaligus **menghapus duplikasi nyata**: peta
  warna `PowerState` sebelumnya disalin di DUA file (`UnitsTable` dan
  `UnitGridWidget`) dan bisa drift; sekarang satu-satunya sumber ada di
  enumnya. Total tiga blok `match` warna manual terhapus.
  Delapan enum: `SessionStatus` (Aktif/Selesai/Dibatalkan), `SessionType`
  (Open Play/Paket), `PaymentMethod` (Tunai/QRIS/Transfer), `PowerState`
  (Menyala/Standby/Tidak terhubung/Belum diketahui), `DeviceAlertType`,
  `DeviceAlertStatus`, `ControlDriver`, `UserRole`.

- **Kartu unit di dashboard tingginya tidak seragam** — badge "1 alert" dulu
  jadi baris sendiri di tengah kartu, jadi unit yang punya alert lebih tinggi
  dari yang tidak, dan grid terlihat bergerigi.
  *Fix:* badge alert dipindah ke baris judul (jadi ikon + angka di sebelah
  badge power), sehingga isi kartu selalu punya jumlah baris yang sama.

- **Tombol aksi kasir dibuat `->button()`**, sebelumnya link teks kecil.
  *Why:* ini dashboard yang dipakai kasir sepanjang hari, sering di layar
  sentuh — target sentuh sebesar tombol jauh lebih layak daripada teks
  setinggi ~16px. Sekalian grid diturunkan dari maksimal 4 kolom ke 3 supaya
  kartu lebih lebar dan tombol tidak berdesakan.

- **Timer dibuat elemen paling menonjol di kartu** (`TextSize::Large` +
  `FontWeight::Bold` + ikon jam; hijau untuk open play, kuning untuk sisa
  waktu paket) — ini angka yang paling sering dilihat kasir, sebelumnya
  ukurannya sama dengan teks lain.

- **Format rupiah disatukan ke `Rupiah::format()`.** Tabel Sesi/Paket/Tipe Unit
  dulu memakai `->money('IDR', locale: 'id')` yang menghasilkan `Rp 167`
  (pakai spasi), sementara halaman Laporan dan notifikasi dashboard memakai
  `Rupiah::format()` yang menghasilkan `Rp167`. Dua format berbeda untuk hal
  yang sama di satu aplikasi.

- **Judul halaman vs label navigasi disamakan** lewat `$pluralModelLabel` —
  navigasi bilang "Riwayat Sesi"/"Alert Perangkat" tapi judul & breadcrumb
  halamannya cuma "Sesi"/"Alert".

- **Kunci setting tidak lagi tampil mentah.** `billing_increment_minutes` kini
  tampil sebagai "Pembulatan billing" dengan kunci teknisnya jadi subtitle
  kecil (`->description()`), dan nilainya jadi badge "1 menit". Petanya
  statis di `Setting::LABELS` — aman karena daftar kunci memang ditentukan
  sistem (`SettingPolicy::create()` selalu `false`, owner hanya mengubah nilai).

## Deteksi TV otomatis + resource Pengguna

- **`UserResource` (owner-only) ditambahkan — sebelumnya TIDAK ADA sama sekali.**
  *Kenapa ini celah nyata:* `RUNBOOK.md` menyuruh "buat user sungguhan lewat
  panel (owner-only) dan nonaktifkan/hapus akun seeder sebelum sistem
  menangani uang sungguhan" — instruksi yang **mustahil dijalankan** karena
  panel tidak punya cara membuat user. Ditemukan saat menyisir model mana
  yang belum punya resource Filament.
  *Detail:* kata sandi wajib saat create, opsional saat edit (kosong =
  dipertahankan, lewat `->dehydrated(fn ($state) => filled($state))`; hashing
  oleh cast `hashed` di model). `UserPolicy::delete()` selalu `false` dan
  `DeleteAction`/bulk-delete dihapus dari halamannya — `rental_sessions`
  menyimpan FK `opened_by`/`voided_by` dengan `restrictOnDelete`, jadi
  menghapus kasir yang pernah membuka sesi akan gagal di level DB sekaligus
  merusak jejak audit. Nonaktifkan lewat `is_active` (langsung ditolak
  `canAccessPanel()`).
  *Model tanpa resource yang memang disengaja:* `Outlet` (V1 single-outlet,
  tidak ada UI ganti-outlet) dan `SessionExtension` (baris anak sesi, sudah
  terlihat lewat riwayat sesi).

- **Deteksi TV otomatis di jaringan — `HomeAssistantDriver::discoverMediaPlayers()`.**
  Permintaan user: mendeteksi sendiri TV yang tersambung ke WiFi/jaringan yang
  sama, tidak hanya mengandalkan DHCP reservation.
  *Why lewat Home Assistant, bukan scanning jaringan sendiri:* HA **sudah**
  menjalankan discovery mDNS/SSDP dan sudah jadi dependency kita untuk
  kontrol TV. Menulis ulang penemuan perangkat (mDNS/ARP/port-scan) berarti
  menambah kompleksitas, dependency, dan permukaan keamanan untuk hasil yang
  lebih buruk — cukup tanya `GET /api/states` (bentuk responsnya diverifikasi
  ke dokumentasi resmi HA sebelum ditulis) lalu saring `media_player.*`.
  *Konsekuensi yang penting untuk operasional:* karena HA mengacu ke
  `entity_id` dan bukan IP, **DHCP reservation jadi tidak wajib** untuk TV
  jalur HA — IP boleh berubah, `entity_id` tetap. `RUNBOOK.md` §14 sudah
  dikoreksi (sebelumnya menuntut DHCP reservation untuk *tiap TV*); untuk
  plug Tasmota reservation tetap disarankan karena jalurnya lewat broker MQTT.
  *Antarmuka:* di form Unit muncul Select "TV terdeteksi di jaringan" yang
  mengisi `control_ref` otomatis, plus `php artisan units:discover` untuk CLI
  yang sekaligus menandai TV mana yang belum dipasangkan ke unit mana pun.
  Gagal terhubung ke HA mengembalikan daftar kosong (bukan exception), jadi
  form tetap bisa dipakai dan `control_ref` tetap bisa diisi manual.

- **BUG DITEMUKAN & DIPERBAIKI saat verifikasi form di browser: dua komponen
  bernama sama (`control_ref`) membuat KEDUANYA hilang.** Percobaan pertama
  memakai `Select::make('control_ref')` untuk HA dan
  `TextInput::make('control_ref')` untuk Tasmota di schema yang sama — hasilnya
  tidak ada field `control_ref` yang ter-render sama sekali. Diperbaiki jadi
  satu `TextInput` `control_ref` + satu Select bantu `discovered_tv` yang
  `->dehydrated(false)` dan hanya mengisi `control_ref` lewat `Set`.
  *Pelajaran:* nama komponen dalam satu schema Filament harus unik; kalau
  tidak, kegagalannya diam-diam (tidak ada error, field-nya hanya lenyap).

- **BUG LAMA IKUT KETAHUAN: `$get()` mengembalikan instance enum, bukan string.**
  Kode lama `->visible(fn ($get) => $get('control_driver') !== ControlDriver::Manual->value)`
  membandingkan instance `ControlDriver` dengan string `'manual'` — hasilnya
  **selalu true**, jadi field "Referensi kontrol" ikut tampil untuk driver
  Manual, padahal driver manual justru yang tidak punya referensi kontrol.
  Sebelumnya tidak kelihatan karena perbandingannya `!==` (selalu lolos);
  baru ketahuan setelah dipakai `===` untuk kondisi baru dan field-nya tidak
  pernah muncul. Diperbaiki dengan helper `UnitForm::driverOf()` yang
  menormalkan kedua bentuk (string maupun enum) — pola yang sama dengan
  `UnitGridWidget::resolvePaymentMethod()`. Diverifikasi ketiga driver di
  browser: `home_assistant` → picker + referensi, `tasmota` → referensi saja,
  `manual` → tidak ada keduanya.

- **`DeviceManager::attempt()` dipakai salah di satu test** — dibungkuskan ke
  `state()` yang mengembalikan `PowerState`, padahal `attempt()` bertipe
  kembalian `?CommandResult`. TypeError-nya ditelan `catch (Throwable)` di
  dalam `attempt()` dan hanya muncul sebagai baris log "Perintah device
  gagal", sehingga test-nya lulus tapi menguji jalur yang salah. Ketahuan
  saat membaca `storage/logs/laravel.log` untuk keperluan lain. Test
  diperbaiki memanggil `driverFor($unit)->state($unit)` langsung.

## Review tim adversarial — penutupan V1

Dijalankan 63 agent (6 dimensi × reviewer, tiap temuan diserang 2 skeptik
independen: satu diminta MEMBANTAH, satu diminta MEREPRODUKSI). 28 temuan
mentah, 44 dari 56 verdict bertahan.

**Catatan proses yang penting:** agent review MENGUBAH source code saat
bereksperimen — satu menghapus `lockForUpdate()` di `StartSessionAction`,
satu menulis mutasi `whereNot('status', Active)` di query pendapatan laporan
(yang akan menghitung sesi VOID sebagai pendapatan). Keduanya terdeteksi
lewat `git diff` dan dipulihkan sebelum commit. *Pelajaran:* jangan pernah
percaya working tree setelah menjalankan agent yang boleh menulis file —
audit `git diff` sebelum commit, dan pastikan file scratch mereka dihapus.

### Diperbaiki (uang & otorisasi)

- **Pembulatan billing 0 bisa disimpan → `DivisionByZeroError` saat menagih.**
  Sesi jadi TIDAK BISA ditutup sama sekali. Dijaga dua lapis: `max(1, …)` di
  `OpenPlayBillingCalculator` (otoritatif, melindungi data lama yang sudah
  terlanjur 0) dan `minValue(1)` di form.
- **Fencing token hanya dicek DI LUAR lock.** Urutan yang merugikan: sweep
  membaca daftar sesi kedaluwarsa → kasir menerima uang perpanjangan →
  sweep menutup sesi itu. Pelanggan bayar, waktunya hangus. Token sekarang
  dicek ULANG di dalam transaksi (`expectedExpiryToken`), diteruskan oleh
  kedua pemanggil otomatis; penutupan manual kasir sengaja tidak mengirim
  token supaya tidak pernah ter-fence.
- **Kasir bisa bulk-delete unit.** Filament menanyakan ability `deleteAny`
  (BUKAN `delete`) untuk bulk action; `UnitPolicy` tidak pernah punya method
  itu, Gate mengembalikan "tidak terdefinisi", dan Filament menganggapnya
  diizinkan. Ditutup di semua lapis + `DeleteAction` di halaman edit dibuang
  (FK `restrictOnDelete` membuatnya melempar QueryException mentah).
- **Dua unit bisa menunjuk TV fisik yang sama** → menutup sesi unit B ikut
  mematikan TV unit A yang masih dipakai & ditagih. Dicegah unique index
  `uq_unit_control_ref`, dicerminkan validasi form, dan TV yang sudah dipakai
  disembunyikan dari daftar discovery.

### Diperbaiki (device & laporan)

- **HA melaporkan `playing`/`paused`/`idle`/`buffering` untuk TV yang MENYALA**
  — semuanya dulu jatuh ke `Unknown`, jadi verifikasi power-off tidak pernah
  membuat alert untuk TV yang jelas menyala.
- **Verifikasi power-off hanya beralert saat state persis `On`** — plug
  Tasmota yang offline menghasilkan `Unknown`, jadi verifikasinya MATI TOTAL
  dan kasir tidak pernah diberi tahu. Sekarang apa pun yang tidak bisa
  dipastikan mati ikut dilaporkan (fail loud, §3.5); unit manual dikecualikan
  karena `ManualDriver` sudah beralert sendiri.
- **Wake-on-LAN membatalkan perintah yang seharusnya ia bantu** —
  `socket_sendto()` memunculkan E_WARNING yang diubah Laravel jadi
  ErrorException, sehingga `media_player.turn_on` di bawahnya tidak pernah
  jalan. Dibungkus try/catch: best-effort sungguhan.
- **Laporan menghitung hari Jakarta memakai UTC.** `APP_DISPLAY_TIMEZONE` ada
  di `.env` sejak Fase 1 tapi **tidak pernah dibaca kode mana pun**. Sekarang
  di-wire ke `config('app.display_timezone')` dan dipakai untuk batas hari,
  jam tersibuk, grouping harian, serta timestamp & nama file CSV.
  *Catatan fixture:* Carbon ber-timezone Jakarta yang disimpan tanpa `->utc()`
  menulis jam dindingnya apa adanya ke kolom — test-nya kini eksplisit.
- **MQTT bridge menelan semua exception**: php-mqtt/client menangkap Throwable
  dari callback lalu mengirimnya ke logger internal yang tidak diisi. Dibungkus
  `guard()` sendiri supaya tercatat di log aplikasi tanpa mematikan daemon.
- **LWT `Online` menyegarkan `last_seen_at` tanpa membawa status relay**,
  padahal kolom itu satu-satunya penanda kesegaran yang dipakai
  `TasmotaDriver::state()` — power_state lama jadi terlihat "masih valid".
  Sekarang bridge meminta status sebenarnya (`queryState()`, publish payload
  kosong ke `cmnd/<ref>/POWER`) dan membiarkan `stat/+/POWER` yang menyegarkan.

### Diperbaiki (kualitas & dokumentasi)

- **Rumus total sesi punya DUA salinan** — `CompleteSessionAction` (yang
  menagih) dan `UnitGridWidget::estimateTotal` (yang ditampilkan). Angka di
  layar dan angka yang ditagih bisa menyimpang diam-diam. Disatukan ke
  `App\Domain\Billing\SessionTotal`; `OpenPlayBillingCalculator` tetap murni.
- **`UnitGridWidget` — dashboard penerima uang — nol coverage.** Ditambah test,
  termasuk yang membuktikan estimasi == tagihan sungguhan.
- **Backup harian tidak akan pernah jalan**: cron dipasang untuk `www-data`
  tapi `/var/backups` dan `/var/log` milik root, sehingga redirect-nya gagal
  sebelum script sempat jalan — dan gagalnya tak terlihat di mana pun. Log
  dipindah ke `storage/logs/` (sudah milik www-data), script gagal keras
  dengan perintah perbaikan yang bisa langsung disalin, dan langkah
  `chown` sekali-jalan ditulis di header crontab.
- **`REVERB_HOST=localhost`** di `.env.example` tanpa peringatan — itu alamat
  yang dipakai BROWSER KASIR, jadi di outlet realtime diam-diam mati (jatuh ke
  polling 15 detik). Diberi komentar eksplisit.
- Koreksi dokumentasi: `state_mismatch` yang tidak pernah diproduksi kode,
  latensi sweep (~90 detik, bukan 30), `supervisorctl restart ctb-queue-worker:*`
  (numprocs=2 = grup), `php artisan test` juga menjalankan suite Concurrency,
  dan instruksi "hapus akun seeder" yang mustahil (akun sengaja tak bisa dihapus).

### Belum dikerjakan (sadar, bukan terlewat)

- `DeviceAlertType::StateMismatch` kini nilai enum yang tidak pernah diproduksi
  kode mana pun. Dibiarkan di schema (nilai kolom tetap valid) — menghapusnya
  butuh migrasi enum tanpa manfaat nyata di V1.
- Uji restore backup terakhir dilakukan di mesin dev; **wajib diulang di server
  production sungguhan** sebagai bagian UAT §14.

## Migrasi: `uq_unit_control_ref` dilebur ke migrasi utama

- **`add_unique_control_ref_to_units_table` dihapus, index-nya dipindah ke
  `create_units_table`.**
  *Why boleh dilebur:* V1 ini BELUM pernah dideploy dan belum pernah menyimpan
  data sungguhan (UAT fisik §14 belum dijalankan). Selama belum ada DB
  production yang sudah menjalankan migrasi lama, melebur `add_` ke `create_`
  aman dan membuat skema terbaca sekali jalan. Setelah production hidup,
  aturannya berbalik: migrasi yang sudah pernah jalan TIDAK BOLEH diubah lagi,
  perubahan skema harus jadi migrasi baru.
  *Diverifikasi:* `migrate:fresh --seed` dari nol lalu `SHOW INDEX FROM units`
  membuktikan `uq_unit_control_ref` benar-benar terbentuk (bukan sekadar file
  yang rapi), dan 147 test tetap hijau di atas skema hasil rebuild.

- **Tiga migrasi `*_activity_log_table` SENGAJA tidak ikut dilebur.** Itu
  migrasi terbitan `spatie/laravel-activitylog`, bukan tulisan kami.
  Menggabungkannya berarti menyimpang dari set migrasi resmi paket dan
  berpotensi bentrok saat paketnya di-upgrade.

## Ronde verifikasi: regresi yang DIPERKENALKAN oleh perbaikan sebelumnya

Review kedua sengaja hanya menyasar commit `517136d..HEAD` — yaitu perbaikan
yang baru saya buat sendiri — dengan asumsi eksplisit "penulisnya terlalu
percaya diri". Terbukti berguna: **tiga dari perbaikan itu memperkenalkan
masalah baru.**

- **Tanggal default laporan mundur sehari (regresi dari perbaikan timezone).**
  `range()` diubah membaca tanggal sebagai jam dinding outlet, tapi `mount()`
  masih membuat tanggalnya dengan `now()` (UTC). Antara 00:00-07:00 WIB kedua
  sisi berselisih 7 jam: laporan default berhenti SEBELUM "sekarang", dan
  pendapatan malam yang baru saja terjadi hilang tanpa tanda apa pun. Di
  tanggal 1 lebih parah — laporan menampilkan seluruh bulan LALU. Ironisnya
  sebelum perbaikan timezone, kedua sisi sama-sama UTC jadi setidaknya
  konsisten. *Pelajaran:* mengubah satu sisi dari pasangan produsen/konsumen
  tanpa mengubah pasangannya justru membuat keadaan lebih buruk.
- **Mengubah unit ke driver Manual mengunci TV-nya selamanya (regresi dari
  gabungan `visible()` + unique index).** Filament tidak menyimpan field yang
  sedang tersembunyi, jadi `control_ref` lama tertinggal di DB. Karena ada
  `uq_unit_control_ref`, TV itu jadi tidak dipakai unit tersebut (driver-nya
  manual) TAPI juga tidak bisa dipakai unit lain. Diperbaiki dengan
  `->dehydratedWhenHidden()` + `->dehydrateStateUsing()`.
  *Catatan API:* `->dehydrated()` saja TIDAK cukup untuk field tersembunyi —
  `isDehydrated()` lebih dulu gugur lewat `isHiddenAndNotDehydratedWhenHidden()`.
  Ini ketahuan karena test-nya gagal, bukan karena membaca kode.
- **Owner bisa mengunci dirinya sendiri keluar (regresi dari UserResource
  baru).** Form membiarkan owner menurunkan perannya sendiri jadi kasir atau
  menonaktifkan akunnya sendiri; tidak ada jalur pemulihan di dalam aplikasi
  (harus `tinker` di server). Field peran & keaktifan kini dinonaktifkan untuk
  akun sendiri.

### Diperbaiki sekalian (bukan regresi, tapi ikut terungkap)

- **`HA_BASE_URL` kosong membuat halaman tambah/ubah unit MATI** dengan
  TypeError, karena form memanggil discovery saat render dan
  `HomeAssistantDriver` menerima `string` (bukan `?string`). Diberi default di
  `config/services.php` supaya yang terjadi hanya "daftar TV kosong".
- **Banjir alert.** Setelah verifikasi power-off dibuat fail-loud, Home
  Assistant yang mati semalaman akan membuat SATU alert per penutupan sesi dan
  menenggelamkan alert lain. Ditambah `DeviceAlert::raiseOnce()`: satu alert
  terbuka per (unit, tipe); alert baru muncul lagi setelah yang lama
  di-acknowledge. Dipakai ketiga tempat pembuat alert.
- **Tanggal berbeda antar layar.** Laporan sudah pakai jam dinding outlet,
  tapi tabel Sesi/Alert/Unit masih UTC — sesi yang sama tertulis beda hari di
  dua layar. Semua kolom waktu kini pakai `timezone: config('app.display_timezone')`.

## Ringkasan laporan: widget statistik native + grafik ApexCharts

- **DEPENDENCY BARU: `leandrocfe/filament-apex-charts` ^5.1.** §2 mewajibkan
  justifikasi tertulis untuk dependency di luar stack terkunci.
  *Justifikasi:* diminta eksplisit oleh pemilik produk. Sebelum dipasang,
  kompatibilitasnya dicek dulu (`composer show --all` → ada seri 5.x untuk
  Filament v5) dan di-`--dry-run` (resolusi bersih, tanpa konflik, tanpa
  security advisory). Filament sendiri tidak punya widget grafik bawaan yang
  setara; alternatifnya menulis pembungkus Chart.js sendiri — lebih banyak
  kode untuk hasil lebih buruk, dan proyek ini sengaja tidak punya build step
  frontend. Plugin ini WAJIB didaftarkan di panel
  (`->plugin(FilamentApexChartsPlugin::make())`); tanpa itu view-nya melempar
  `LogicException` — ketahuan dari test, bukan dari membaca dokumentasi.

- **Blok "Ringkasan" (grid TextEntry) diganti `SalesStatsWidget`** yang
  extends `StatsOverviewWidget` bawaan Filament — kartu statistik standar
  lengkap dengan ikon, deskripsi, warna, dan sparkline 14 hari terakhir.
  Sebelumnya tiga TextEntry dalam Grid: informatif tapi bukan komponen
  statistik, jadi tidak dapat gaya kartu maupun sparkline.

- **`SalesSummary` dibuat sebagai satu-satunya sumber angka rekap.**
  *Why:* begitu halaman Laporan, kartu statistik, dan grafik sama-sama butuh
  angka yang sama, tanpa ini rumus + konversi timezone akan tersalin TIGA
  kali. Persis pola masalah yang sudah pernah terjadi antara estimasi
  dashboard dan tagihan sungguhan (lihat `SessionTotal`). Halaman Laporan
  kini hanya memanggilnya, tidak lagi query sendiri.

- **Grafik memakai satu titik per hari termasuk hari kosong**, dibatasi 366
  titik. Garis yang melompati hari tanpa transaksi membuat tren terlihat
  lebih ramai dari kenyataan; batas 366 mencegah rentang sangat lebar
  menggambar ribuan titik.

- **Formatter sumbu Y & tooltip lewat `extraJsOptions()` (RawJs), bukan
  `getOptions()`.** Nilai string di `getOptions()` dikirim sebagai teks, bukan
  fungsi JavaScript. Sumbu Y disingkat "rb"/"jt" karena rupiah penuh membuat
  labelnya sangat lebar; tooltip tetap menampilkan angka utuh.

- **BUG DITEMUKAN SAAT VERIFIKASI: `canView() => false` SALAH untuk
  menyembunyikan widget dari dasbor.** Percobaan pertama memakai itu supaya
  widget laporan tidak ikut ter-discover ke Dasbor — hasilnya halaman Laporan
  balas **403**, karena `Widget\Concerns\CanAuthorizeAccess` memakai
  `abort_unless(static::canView(), 403)` saat widget di-mount. Solusi yang
  benar: `App\Filament\Pages\Dashboard` menyebut widgetnya secara EKSPLISIT
  lewat `getWidgets()`. Ketahuan dari browser, bukan dari test — test-nya
  hijau karena tidak ada yang merender halaman Laporan secara nyata saat itu.

## Polish laporan: format uang, baris total, dan auto-refresh

- **`Rupiah::format()` kini `Rp 135.000` (pakai spasi)** dan jam tersibuk
  `10:00 – 11:00` (spasi mengapit tanda pisah). Perubahan di satu tempat ini
  otomatis berlaku ke seluruh panel karena semua sisi uang sudah disatukan ke
  `Rupiah::format()` sebelumnya.

- **Baris `TOTAL (n sesi)` ditambahkan ke rincian per metode bayar & per tipe
  unit.** Dihitung di `SalesSummary::summarize()` dari sumber yang sama dengan
  baris-baris di atasnya — BUKAN dijumlah ulang di UI — supaya totalnya
  dijamin cocok. Rentang kosong tidak menampilkan baris total sama sekali.

- **Rincian kini ikut bertambah otomatis.** Sebelumnya hanya kartu statistik &
  grafik yang menyegar sendiri (widget Filament punya polling bawaan 5 detik);
  dua tabel rincian hidup di HALAMAN, dan halaman tidak punya listener apa pun
  — angkanya diam sampai owner memuat ulang. Sekarang halaman mendengarkan
  `session.ended` lewat Reverb (§6) dengan `->poll('30s')` di tabel sebagai
  cadangan kalau WebSocket putus, persis pola dashboard kasir.

- **Polling widget diturunkan 5s → 30s.** Default bawaan 5 detik terlalu boros
  untuk laporan yang meng-query seluruh rentang tanggal; push Reverb membuat
  angka tetap terasa berubah seketika, jadi polling hanya perlu jadi jaring
  pengaman.

## Rincian harian: dibuat cukup untuk menutup kas

- **Kolom rincian harian diperluas jadi Tanggal / Sesi / Tunai / QRIS /
  Transfer / Total**, plus Rata-rata per sesi & Kontribusi (%) yang bisa
  dimunculkan lewat toggle kolom.
  *Why bukan sekadar "menambah kolom":* rincian sebelumnya (tanggal, jumlah
  sesi, total) tidak bisa dipakai untuk pekerjaan nyata di akhir hari.
  Owner/kasir perlu tahu berapa uang TUNAI yang mestinya ada di laci, terpisah
  dari QRIS & transfer yang masuk rekening — angka total saja tidak menjawab
  itu. Deskripsi section-nya menyebut ini eksplisit supaya maksud kolomnya
  jelas: "Tunai harus cocok dengan isi laci; QRIS & transfer dengan mutasi
  rekening."

- **`SalesPaymentMixChart` (ApexCharts, batang bertumpuk)** menampilkan
  komposisi yang sama secara visual. Melengkapi `SalesRevenueChart` yang hanya
  menunjukkan totalnya: yang ini menjawab "hari itu uangnya masuk lewat mana".
  Urutan warnanya sengaja sama dengan badge metode bayar di tabel Riwayat Sesi
  (Tunai hijau, QRIS biru, Transfer kuning) supaya tidak perlu dihafal ulang.

- **Deret grafik dijamin sepanjang labels**, termasuk hari & metode tanpa
  transaksi (nilainya 0). Kalau tidak, batang akan bergeser hari dan grafiknya
  berbohong — ada test khusus yang menjaga panjang tiap deret.

- **Pemecahan per metode dihitung di `SalesSummary`, bukan di kolom tabel**,
  dan ada test yang memastikan `cash + qris + transfer === revenue`. Ini
  laporan uang: kolom yang tidak menjumlah ke totalnya sendiri lebih buruk
  daripada tidak ada kolom sama sekali.

## Dasbor: bug tombol meluber & ringkasan outlet

- **BUG DIPERBAIKI: aksi ketiga meluber keluar kartu.** Saat sebuah unit
  menjalankan sesi PAKET, kartunya memuat tiga aksi (Perpanjang + Stop & Bayar
  + daya). Dengan label penuh, tombol ketiga melewati batas kartu dan menimpa
  kartu sebelahnya — terlihat jelas di grid 3 kolom. Aksi daya diubah jadi
  tombol ikon (`->iconButton()`) dengan tooltip: ia memang aksi bantu, dan
  meringkasnya membuat dua aksi utama tetap berlabel penuh. Tidak menambah
  kolom grid atau memperkecil font, yang hanya akan memindahkan masalahnya.

- **`OutletOverviewWidget` di atas grid unit** — unit terpakai / total,
  pendapatan HARI INI, dan alert yang belum ditangani. Sengaja dibatasi tiga
  angka yang benar-benar dipakai kasir sambil berdiri di depan layar; sisanya
  sudah ada di Laporan. Batas "hari ini" mengikuti jam dinding outlet, bukan
  UTC — kalau tidak, sesi jam 01:00 WIB (masih jam operasional) akan dihitung
  ke hari kemarin saat kasir mencocokkan laci. Ada test yang menguncinya.

- **`$isLazy = false` pada widget dasbor.** Tanpa itu widget hanya
  menampilkan kotak KOSONG — pola yang sama persis sudah pernah terjadi pada
  `UnitGridWidget` di Fase 4 dan sudah dicatat waktu itu, tapi terulang karena
  widget baru tidak otomatis mewarisi keputusan itu.

## Jebakan Filament v5 yang ditemukan saat memoles UI (bukan dari dokumentasi)

Semua di bawah ini gagal **dalam diam**: tidak ada error, tidak ada log, hanya
tampilan yang salah. Ketujuhnya baru ketahuan dari menjalankan aplikasinya,
bukan dari membaca dokumentasi — karena itu dicatat di sini, bukan cuma di
komentar kode.

- **Isi modal aksi TIDAK bisa dicek dengan `assertSee()`.** Filament v5
  merender modal di partial terpisah (`wire:partial="action-modals"`) yang
  KOSONG pada render awal, jadi `assertSee` selalu gagal walau modalnya normal.
  Ini sempat membuat saya menyimpulkan modalnya rusak dan membongkar kode yang
  sebenarnya benar. Cara yang benar: `assertActionMounted()` + memeriksa state
  yang dihasilkan (mis. status sesi di DB).

- **`replaceMountedAction()` membuang cache schema tapi meninggalkan
  `$discoveredSchemaNames`** (properti Livewire yang persist antar request).
  Aksi pengganti yang TIDAK punya schema membuat request berikutnya mencari
  `mountedActionSchema0` yang sudah tidak ada, dan meledak. Karena itu modal
  konfirmasi langkah kedua Stop & Bayar diberi isi nyata (nominal besar),
  bukan dibiarkan kosong.

- **Utility Tailwind sembarangan TIDAK ada di CSS bawaan Filament.** Proyek ini
  sengaja nol build frontend, jadi `->extraAttributes(['class' => 'mx-auto'])`
  menempelkan kelas yang tidak punya aturan apa pun — diam-diam tidak berefek.
  Pengganti yang benar: style inline. Lihat konstanta
  `UnitGridWidget::SEGMENTED_CONTROL`.

- **`columns(2)` berarti `['lg' => 2]`**, bukan "dua kolom". Form baru jadi dua
  kolom mulai 1024px, sehingga SELURUH tablet menumpuk seperti HP. Semua form
  resource kini memakai `['md' => 2]`; filter Laporan memakai `['default' => 2]`
  karena dua tanggal pendek muat berdampingan di lebar berapa pun — dan
  "Dari–Sampai" memang dibaca sebagai satu rentang, bukan dua isian terpisah.

- **`helperText()` selalu rata kiri** dan tidak bisa ditengahkan. Di modal yang
  seluruhnya rata tengah, keterangan harus jadi `TextEntry` tersendiri dengan
  `->alignCenter()`.

- **`Split` membagi lebar rata ke semua kolom.** Badge ikut memuai sehingga
  titik berhentinya bergeser tergantung ada-tidaknya badge lain — itu penyebab
  badge status tidak pernah sejajar antar kartu. `->grow(false)` pada badge
  membuat hanya kode unit yang memuai.

- **`RichEditor` menyimpan state sebagai dokumen TipTap (array bersarang)**,
  bukan string HTML. Memaksanya jadi string di dalam rule validasi melempar
  "Array to string conversion". Yang tersimpan ke DB tetap HTML.

## Dropdown native: satu default global, bukan ditempel per field

`Select`, `DatePicker`, `DateTimePicker`, `SelectFilter`, dan `SelectColumn`
bawaannya memakai `<select>` asli browser. Di HP kontrol itu dirender OLEH
SISTEM OPERASI, DI LUAR kotak modal — hasilnya panel melayang di pojok layar,
lepas dari form yang memicunya. Setiap resource kena, dan hanya terlihat dari
layar kecil.

Diatur sekali lewat `configureUsing()` di `AppServiceProvider`, bukan ditempel
per field: kalau per field, setiap `Select` baru yang ditulis nanti mengulang
bug yang sama dan baru ketahuan setelah dilihat di HP. Enam pemanggilan
`->native(false)` yang sudah tersebar dihapus supaya tidak ada dua sumber
aturan. Dijaga `tests/Feature/Filament/MobileDropdownDefaultsTest.php`.

## Tabel resource: kolom penting dulu, konteks menyusul

Riwayat Sesi punya 11 kolom — di HP semuanya dipaksa muat dan harus digeser
menyamping. Kolom sekunder kini memakai `->visibleFrom('md'|'lg'|'xl')`.
Aturannya: yang tersisa di layar HP adalah yang dipakai MENGAMBIL KEPUTUSAN
(siapa, berapa, statusnya apa); yang jadi konteks tambahan naik ke breakpoint
lebih besar. Di desktop tidak ada yang hilang.

## Stop & Bayar: dua langkah untuk Open Play, satu konfirmasi untuk Paket

Sesi **Paket** sudah lunas di muka, jadi menutupnya bukan transaksi uang —
cukup konfirmasi ringkas, tanpa field.

Sesi **Open Play** memindahkan uang saat itu juga, jadi kasir memilih metode
bayar dulu, lalu MENEGASKAN lewat modal kedua bahwa uangnya benar-benar
diterima. Sekali klik untuk sesuatu yang tidak bisa dibatalkan terlalu mudah
salah tekan. Dijaga test: sesi TIDAK boleh tertutup di langkah pertama.

Notifikasi void juga ditulis ulang jadi konsekuensi, bukan konfirmasi: nominal
yang keluar dari laporan, apakah TV ikut dimatikan (`$wasActive` dibaca SEBELUM
void — setelah statusnya berubah, informasi itu hilang), dan siapa yang
bertanggung jawab. Sengaja `warning` dan `persistent()`, bukan hijau yang
hilang sendiri: angka itu yang dicocokkan saat menutup kas.

Try/catch untuk void ganda sempat ditambahkan lalu **dibuang lagi** setelah
test membuktikannya dead code — Filament menolak me-mount maupun menjalankan
aksi yang sudah tidak `visible`, dan penjaga terakhirnya sudah ada di
`VoidSessionAction` dengan test tersendiri.

## Pemindai jaringan lokal: sistem tidak lagi buta tanpa Home Assistant

Sebelumnya satu-satunya cara sistem tahu ada TV adalah bertanya ke Home
Assistant. Kalau HA mati, belum diatur, atau tokennya salah, sistem buta total
— dan operator tidak bisa membedakan "TV-nya mati" dari "HA-nya bermasalah".

`App\Domain\Devices\NetworkScanner` memindai sendiri lewat SSDP (M-SEARCH ke
239.255.255.250:1900), protokol yang sama yang dipakai HA. Nol dependensi baru:
`ext-sockets` sudah aktif. Tersedia dari panel (aksi "Pindai jaringan" di
halaman Unit) dan dari terminal (`php artisan devices:scan`) — yang kedua
penting saat panelnya sendiri bermasalah.

Terbukti di jaringan sungguhan: menemukan `TCL Smart TV (192.168.100.7)` dan
tidak menandai routernya sebagai TV.

Empat hal yang ditemukan saat membangunnya:

- **Socket WAJIB di-bind ke IP LAN, bukan `0.0.0.0`.** Mesin dengan banyak
  interface mengirim multicast lewat yang dipilih kernel — hasilnya nol balasan
  padahal TV-nya jelas menyala. `IP_MULTICAST_IF` tidak dipakai karena macOS
  menolak nilai berupa alamat IP.
- **Router juga menjawab SSDP.** Tanpa saringan lintas-merek (DLNA
  MediaRenderer, Chromecast/DIAL), daftarnya menyesatkan.
- **Merek dari deskripsi UPnP tidak bisa dipercaya.** TV TCL melapor sebagai
  "Microsoft Corporation" karena yang menjawab adalah renderer DLNA-nya. Kolom
  merek dibuang; nama ramah + IP yang dipakai.
- **Deskripsi UPnP perangkat murah sering XML cacat** — parser sungguhan gagal
  total. Dibaca regex, cuma tiga nilai yang dibutuhkan.

**Batas yang dijaga:** hasil pemindaian TIDAK bisa dipakai sebagai
`control_ref`. SSDP memberi IP; `control_ref` harus `entity_id` HA atau topic
Tasmota. Mengisikan IP ke sana membuat unit TAMPAK terpasang padahal tidak akan
pernah bisa dikontrol. Pemindai ini diagnosis, bukan jalur pemasangan — dan
justru pemisahan itu yang berguna: kalau TV muncul di pemindaian tapi tidak di
daftar HA, yang bermasalah HA-nya.

Satu pengecualian yang jujur: saat memilih TV hasil pindai, `control_ref` ikut
terisi HANYA kalau di HA ada TEPAT SATU `media_player` yang namanya sama
persis. Kalau ada dua yang mirip, sengaja dibiarkan kosong — menebak berarti
unit ini bisa mengendalikan TV milik unit lain, dan itu baru ketahuan setelah
pelanggan duduk di depan layar yang tiba-tiba mati.

## Wake-on-LAN yang gagal dalam diam (bug nyata, bukan hipotetis)

`arp` di macOS dan banyak halaman admin router membuang nol di depan tiap oktet
MAC — router di jaringan uji terbaca `80:60:36:69:2f:6`, sebelas digit.
`WakeOnLan::packMac()` menuntut TEPAT dua belas dan mengembalikan `false` tanpa
log, tanpa pesan.

Artinya sebelum ini: operator menyalin MAC dari routernya, menyimpannya, dan
Wake-on-LAN TIDAK AKAN PERNAH jalan — tanpa satu pun petunjuk kenapa. Kasir
akan menyimpulkan TV-nya rusak.

MAC kini dinormalkan sebelum disimpan (`2f:6` → `2f:06`, `-` → `:`, huruf besar
→ kecil) dan format yang benar-benar salah ditolak dengan pesan. Field `tv_mac`
juga bisa diisi otomatis dari hasil pemindaian, karena MAC tidak pernah
tertulis di badan TV dan satu digit salah sudah cukup untuk menggagalkannya.

## Daftar kosong yang berbohong

"TV terdeteksi di jaringan" menampilkan pesan bawaan Filament ("Tidak ada
pilihan yang tersedia") untuk TIGA sebab yang sangat berbeda: HA belum diatur,
HA terhubung tapi tidak ada entity bebas, dan benar-benar tidak ada. Pesan itu
terbaca sebagai "tidak ada TV di jaringan" padahal yang belum ada justru
`HA_TOKEN`-nya — dan operator akan mengejar masalah yang salah. Ketiganya kini
dibedakan lewat `HomeAssistantDriver::isConfigured()`.

## Tombol Hapus pindah ke footer form

Di header, Hapus berdiri sendirian di atas seperti aksi utama halaman —
padahal ia yang paling merusak; di HP letaknya persis di bawah jempol saat
halaman baru dibuka. Di footer ia baru terjangkau setelah menggulir melewati
seluruh form, dan terpisah dari Simpan oleh Batal. Ditulis sekali di trait
`App\Filament\Concerns\DeletesFromFormFooter`, dipakai 3 halaman.

**Pengaturan sengaja TIDAK memakai trait itu**: setting tidak pernah bisa
dihapus sama sekali. `Setting::get('billing_increment_minutes')` dipakai di
jalur penagihan Open Play, jadi baris yang hilang membuat perhitungan
diam-diam jatuh ke default. `SettingPolicy::delete()` sudah menegakkannya;
kini dijaga test supaya larangan itu benar-benar sampai ke tombol di layar.

## Pelajaran proses: subagent review pernah mengubah kode sumber

Diulang di sini karena terbukti berulang: agen review yang diberi akses tulis
pernah melemahkan `lockForUpdate()` dan filter pendapatan. Setiap sesi review
WAJIB ditutup dengan `git diff` sebelum apa pun dilanjutkan.

## Dependensi baru: `bacon/bacon-qr-code` (§2 — justifikasi tertulis)

Kios bekerja dengan pelanggan MEMINDAI kode di unit. Tanpa cara membuat kode
itu, seluruh fiturnya tidak bisa ada — dan stikernya harus dibuat ulang tiap
kali unit ditambah atau alamat servernya berubah, jadi menggambarnya sekali di
luar sistem bukan jalan keluar.

Kenapa tidak dihindari:

- **Bukan di sisi klien.** Pustaka JavaScript berarti CDN (panel LAN-only, §14
  melarangnya) atau build frontend (proyek ini sengaja nol build).
- **Bukan layanan luar.** Mengirim alamat unit ke API pihak ketiga hanya untuk
  menggambar kotak hitam-putih menambah ketergantungan internet pada mesin yang
  harus tetap bekerja saat internet mati.
- **Bukan ditulis sendiri.** Encoder QR adalah Reed–Solomon plus tabel masking;
  menulisnya sendiri berarti memelihara kode kriptografis-berat demi menghindari
  satu paket kecil.

Kenapa yang ini:

- Murni PHP, tanpa ekstensi tambahan bila keluarannya **SVG** — dan SVG justru
  yang dibutuhkan untuk stiker cetak (tajam di ukuran berapa pun).
- Sudah dikenal ekosistem Laravel; bahkan sudah tercantum sebagai `suggest` oleh
  dependensi Filament yang terpasang.
- Tidak menyentuh jalur uang sama sekali: ia hanya menggambar tautan.

## Menampilkan QR DI LAYAR TV: diuji di perangkat asli, tidak bisa lewat HA

Diuji langsung pada TCL Android TV yang sudah terpasang, bukan disimpulkan dari
dokumentasi:

- `media_player.play_media` dengan `media_content_type: url` **dijawab HTTP 200
  oleh Home Assistant tetapi TV tidak pernah mengambil halamannya** — nol
  permintaan masuk ke server, dan app yang aktif berubah jadi `android`
  (dialog sistem), bukan browser.
- `source_list` entity-nya KOSONG, jadi tidak ada daftar app yang bisa dipilih.
- Peluncuran per paket: `com.tcl.browser` ADA di TV ini; `com.android.chrome`,
  `com.google.android.tv.browser`, dan `org.mozilla.firefox` semuanya memantul
  ke Play Store (tidak terpasang). Browser bawaan TCL bisa dibuka, tapi TIDAK
  bisa disuruh membuka alamat tertentu lewat HA.

Kesimpulan: menampilkan halaman kita di layar TV **tidak bisa lewat Home
Assistant**. Yang tersisa: aplikasi Cast Receiver terdaftar Google (butuh HTTPS
publik — bentrok dengan §14 LAN-only), aplikasi Android TV sendiri (sideload per
unit), atau stik HDMI kecil per unit yang menampilkan halaman kios.

**Yang penting: alur yang diinginkan TIDAK bergantung pada ini.** Stiker QR di
bingkai TV memberi hasil yang sama persis — pindai, masuk, main, TV menyala.
Satu-satunya yang hilang adalah QR-nya lenyap dari layar saat ada yang bermain,
dan itu hal visual, bukan fungsional. Membangun kiosnya lebih dulu dengan stiker
membuat seluruh alurnya jalan hari ini; layar TV bisa menyusul kapan saja tanpa
mengubah apa pun di belakangnya.

## `php artisan serve` mengikat ke 127.0.0.1 — kios mustahil dijangkau

Ditemukan saat menguji TV. Server pengembangan bawaan hanya mendengarkan di
localhost, jadi TV MAUPUN HP pelanggan tidak akan pernah bisa membuka halaman
kios. Untuk menguji dari perangkat lain: `php artisan serve --host=0.0.0.0`.
Di outlet, nginx (lihat `deploy/`) memang sudah mendengarkan di semua alamat —
jadi ini murni jebakan saat pengembangan, tapi jebakan yang membuat seluruh
fitur tampak rusak padahal kodenya benar.

## OTP WhatsApp: WAHA dipilih, dipasang belakangan

Pemilik produk memilih **WAHA** (WhatsApp HTTP API, self-hosted) sebagai
pengirim OTP, tapi belum sekarang. Karena itu `OtpChannel` sengaja dibiarkan
sebagai kontrak tipis — satu nomor, satu kode — dan pemilihannya ada di SATU
baris di AppServiceProvider. Memasang WAHA nanti tidak menyentuh satu pun
aturan keamanan OTP yang sudah ditest.

Yang menguntungkan: WAHA berjalan di Docker (cocok dengan
`docker-compose.devices.yml` yang sudah ada) dan tidak menagih per pesan.

**Risiko yang harus tetap diingat saat memasangnya:** WAHA menyambung lewat
WhatsApp Web, bukan API resmi Meta. Meta rutin memblokir nomor yang memakai
cara ini. Untuk sistem yang memegang uang, nomor terblokir berarti **tidak ada
pelanggan yang bisa masuk sama sekali** sampai ada nomor pengganti — jadi:

- Pakai nomor khusus, BUKAN nomor pribadi pemilik outlet.
- Sediakan jalur masuk cadangan yang tidak lewat WhatsApp.

## PIN tetap ada, bukan digantikan OTP

OTP terlihat lebih modern, tapi ia memindahkan syarat masuk ke INTERNET. Di
outlet, internet putus berarti tidak ada satu pun pelanggan yang bisa masuk —
padahal TV, panel, dan database semuanya masih hidup di jaringan lokal.

Karena itu PIN dipertahankan sebagai jalur masuk yang bekerja offline, dan OTP
menjadi cara mendaftar/memulihkan akun. Keduanya sudah ada dan ditest; yang
belum hanyalah penyedia pengirimannya.

## Backlog eksplisit (bukan dikerjakan, dicatat sebagai pengingat)

- Akun pelanggan + saldo/top-up tanpa expiry (V2). **Diminta lagi & ditegaskan
  ditunda oleh pemilik produk**: field "Nama pelanggan" di modal Mulai Sesi
  nantinya membaca dari data member (dengan tetap bisa diketik manual untuk
  tamu), plus pengisian saldo per member. Bentuk V1 sekarang — teks bebas —
  sengaja dipertahankan karena tanpa tabel member, saldo, dan mutasinya, isian
  member hanyalah teks yang menyamar jadi relasi. Saat V2 dikerjakan, field
  ini menjadi Select ber-`getSearchResultsUsing()` + `createOptionForm()`
  supaya tamu tanpa akun tetap bisa dilayani tanpa memaksa buat member.
- **Kios QR di layar TV — self-service tanpa kasir (V2+, ide pemilik produk).**
  Alur yang diinginkan: TV mati menampilkan QR → pemain memindai → pilih login
  atau main sebagai tamu → main (bisa berjam-jam sampai sehari penuh) → selesai
  → bayar → QR muncul lagi untuk pemain berikutnya.

  **Ini permukaan produk baru, bukan fitur tambahan.** Menampilkan halaman kita
  sendiri di TV butuh Cast Receiver app terdaftar di Google + hosting HTTPS
  publik, sementara §14 mengharuskan panel LAN-only tanpa port forwarding.
  Alternatifnya aplikasi Android TV sendiri (sideload) atau stik HDMI per unit.
  Ketiganya proyek tersendiri.

  **Hambatan sesungguhnya bukan teknis, melainkan jaminan pembayaran.** Di V1
  kasir memegang uangnya di depan; kios mandiri menghapus penjaga itu. Kalau
  pembayaran terjadi di AKHIR dan tidak ada kasir, tidak ada apa pun yang
  menghalangi orang bermain 8 jam lalu pergi.

  Konsekuensinya: **"tanpa akun" dan "bayar di akhir" TIDAK BISA digabung.**
  Tamu tanpa akun wajib prabayar (saldo/QRIS di muka); yang bayar di akhir
  wajib punya akun dengan saldo atau otorisasi pembayaran tersimpan. Tiga
  penutup risiko yang mungkin: saldo prabayar (paling aman, sudah di backlog),
  pre-auth Midtrans (butuh gateway yang mendukung hold), atau deposit (butuh
  kasir juga — sehingga menghapus alasan kios ada).

  Keputusan model pembayaran ini WAJIB diambil sebelum satu baris kode kios
  ditulis. Membangun kiosnya lebih dulu berarti membangun mesin yang bisa
  menagih tapi tidak bisa menjamin tertagih.

- Payment gateway (Midtrans) + verifikasi otomatis bukti transfer (V2)
- Notifikasi WhatsApp (V2, butuh nomor pelanggan yang belum ada sumbernya di V1)
- Engine diskon/voucher (V2)
- Multi-outlet penuh: UI ganti-outlet, isolasi query per outlet, laporan gabungan
  lintas outlet (V2 — fondasi kolom sudah ada sejak V1)
