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

## Backlog eksplisit (bukan dikerjakan, dicatat sebagai pengingat)

- Akun pelanggan + saldo/top-up tanpa expiry (V2)
- Payment gateway (Midtrans) + verifikasi otomatis bukti transfer (V2)
- Notifikasi WhatsApp (V2, butuh nomor pelanggan yang belum ada sumbernya di V1)
- Engine diskon/voucher (V2)
- Multi-outlet penuh: UI ganti-outlet, isolasi query per outlet, laporan gabungan
  lintas outlet (V2 — fondasi kolom sudah ada sejak V1)
