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

## Backlog eksplisit (bukan dikerjakan, dicatat sebagai pengingat)

- Akun pelanggan + saldo/top-up tanpa expiry (V2)
- Payment gateway (Midtrans) + verifikasi otomatis bukti transfer (V2)
- Notifikasi WhatsApp (V2, butuh nomor pelanggan yang belum ada sumbernya di V1)
- Engine diskon/voucher (V2)
- Multi-outlet penuh: UI ganti-outlet, isolasi query per outlet, laporan gabungan
  lintas outlet (V2 — fondasi kolom sudah ada sejak V1)
