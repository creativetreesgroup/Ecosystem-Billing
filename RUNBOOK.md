# RUNBOOK — Creative Trees Billing Game

Operasional harian, prosedur insiden, dan tugas yang sengaja di luar jangkauan AI. Lihat [`README.md`](README.md) untuk topologi & ERD, [`DECISIONS.md`](DECISIONS.md) untuk alasan teknis di balik tiap pilihan.

## Proses yang harus selalu hidup

Dikelola Supervisor (`deploy/supervisor/*.conf`):

| Proses | Perintah | Kalau mati |
|---|---|---|
| Nginx + PHP-FPM | — | Panel tidak bisa diakses sama sekali |
| Queue worker | `queue:work` | Expiry sesi, warning, reconciliation power-off berhenti jalan |
| Scheduler | `schedule:work` | `units:poll-state` & `sessions:sweep-expired` berhenti |
| Reverb | `reverb:start` | Dashboard berhenti update realtime, fallback ke polling 15 detik |
| MQTT bridge | `bridge:mqtt-listen` | Unit Tasmota berhenti update `power_state` |

Cek status: `sudo supervisorctl status`. Restart satu proses: `sudo supervisorctl restart ctb-<nama>`.
(Queue worker berjalan sebagai grup karena `numprocs=2`, jadi perintahnya
`sudo supervisorctl restart ctb-queue-worker:*` — tanpa `:*` supervisor menjawab
"no such process".)

---

## Insiden: TV tidak mati saat sesi habis

1. Cek dashboard — kalau ada badge alert merah di kartu unit, klik untuk baca pesannya (`power_off_failed`, ditulis oleh `VerifyUnitPoweredOffJob` 10 detik setelah setiap perintah power-off, lihat `DECISIONS.md` Fase 5).
2. Kalau alertnya `power_off_failed` dari unit `control_driver=manual`: **matikan TV manual** — driver ini tidak pernah bisa mengontrol TV, itu memang cara kerjanya.
3. Kalau unit `home_assistant`: buka Home Assistant (`http://<host>:8123`), cari entity `media_player.*` unit terkait, cek statusnya. Kalau HA sendiri tidak bisa mematikan, TV kemungkinan hilang dari jaringan — cek TV menyala & terhubung WiFi/LAN.
4. Kalau unit `tasmota`: cek plug menyala (LED status), cek `bridge:mqtt-listen` masih hidup (`supervisorctl status`), cek Mosquitto masih hidup (`docker compose -f docker-compose.devices.yml ps`).
5. Setelah TV mati manual, acknowledge alert-nya di dashboard supaya tidak terus muncul untuk kasir berikutnya.
6. **Billing sudah selesai secara benar** di langkah ini apa pun hasilnya di atas — status sesi & `total_amount` tidak pernah menunggu konfirmasi TV (prinsip arsitektur #1).

## Insiden: unit unreachable

- Badge `power_state = unreachable` muncul di dashboard dalam ≤ 90 detik (`units:poll-state` tiap 30 detik untuk unit HA; LWT MQTT langsung untuk unit Tasmota).
- Cek fisik: TV/plug menyala? Kabel LAN/WiFi tersambung? Router/switch/AP menyala?
- Cek Home Assistant/Mosquitto sendiri hidup (lihat tabel proses di atas + `docker compose -f docker-compose.devices.yml ps`).
- **Dilarang mengandalkan ping** sebagai bukti TV menyala — networked standby tetap membalas ping walau layar mati (§7). Satu-satunya sumber kebenaran adalah `media_player` state HA atau `stat/+/POWER` MQTT.

## Insiden: Reverb mati

- Dashboard tetap berfungsi penuh lewat fallback `->poll('15s')` — TIDAK ada downtime fungsional, hanya delay update naik dari ≤2 detik ke ≤15 detik.
- Restart: `sudo supervisorctl restart ctb-reverb`.
- Verifikasi browser reconnect: buka console browser, `window.Echo.connector.pusher.connection.state` harus kembali `connected` dalam beberapa detik setelah proses hidup lagi.

## Insiden: Queue worker mati

- Sesi paket yang harusnya berakhir otomatis akan TERLAMBAT (bergantung expiry job), tapi `sessions:sweep-expired` (scheduler, tiap menit) akan menutupnya paling lambat ~90 detik setelah `ends_at` (sweep jalan tiap menit dan hanya mengambil sesi yang sudah lewat >30 detik) — **selama scheduler masih hidup**. Kalau scheduler JUGA mati, sesi paket menumpuk tanpa auto-close sampai kasir stop manual.
- Restart: `sudo supervisorctl restart ctb-queue-worker`.
- Setelah restart, job yang tertunda di tabel `jobs` akan diproses ulang otomatis — tidak ada data yang hilang (queue driver `database`, bukan in-memory).

## Insiden: listrik padam & recovery

1. Pasang UPS untuk server + router + switch (§14 — tugas manusia, belum tentu sudah terpasang, cek fisik).
2. Saat listrik kembali: server (kalau tanpa UPS shutdown-otomatis) boot ulang → semua proses Supervisor `autostart=true` sudah otomatis hidup kembali, termasuk Nginx/PHP-FPM/MySQL via systemd.
3. Sesi yang sedang `active` saat listrik padam: begitu server hidup lagi, `sessions:sweep-expired` akan menutup sesi paket yang `ends_at`-nya sudah lewat. Sesi open play TIDAK punya `ends_at` — akan tetap `active` sampai kasir menutupnya manual (perlu klarifikasi jam sungguhan ke pelanggan yang mungkin sudah pulang selama listrik padam).
4. Cek Home Assistant & Mosquitto (Docker) ikut hidup lagi: `docker compose -f docker-compose.devices.yml ps`. Kalau `restart: unless-stopped` tidak jalan otomatis (mis. Docker daemon sendiri belum auto-start), `docker compose -f docker-compose.devices.yml up -d`.

---

## Backup & restore

Backup harian otomatis (`deploy/backup/backup-database.sh`, cron `deploy/backup/crontab`, retensi 14 hari) — lihat isi file untuk detail.

### Uji restore — WAJIB, sudah pernah dilakukan sekali di sesi pengembangan ini

Restore yang belum pernah dicoba bukan backup, cuma harapan. Prosedur ini **sudah dijalankan end-to-end** terhadap database development sungguhan (bukan hanya didokumentasikan secara teoritis):

```bash
# 1. Backup database yang sedang jalan
./deploy/backup/backup-database.sh
# → deploy/backup/backup-database.sh menulis ke $BACKUP_DIR (default /var/backups/creative-trees-billing)

# 2. Buat database kosong terpisah untuk uji restore (JANGAN restore ke DB production langsung
#    tanpa alasan kuat — restore menimpa seluruh isi database target)
mysql -u root -p -e "CREATE DATABASE ctb_restore_test;"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON ctb_restore_test.* TO 'ctb_app'@'localhost';"

# 3. Restore ke database uji itu (APP_DIR dioverride ke salinan .env yang DB_DATABASE-nya
#    diarahkan ke ctb_restore_test, supaya tidak menyentuh database asli)
APP_DIR=/path/ke/salinan/env ./deploy/backup/restore-database.sh /var/backups/creative-trees-billing/<file>.sql.gz

# 4. Bandingkan jumlah baris tabel utama antara database asli & hasil restore
mysql ... -e "SELECT COUNT(*) FROM rental_sessions;"   # ulangi untuk users, units, device_alerts
```

**Temuan penting dari uji ini** (dicatat supaya tidak terulang): `mysqldump`/`mysql` client **harus** dari vendor yang sama dengan server (MySQL client untuk MySQL server — bukan client MariaDB). Kolom generated `active_unit_id` di `rental_sessions` (lihat ERD di README) di-dump sebagai nilai eksplisit `NULL` oleh client MariaDB, yang lalu DITOLAK MySQL 8 saat restore (`ERROR 3105`) karena generated column tidak boleh diberi nilai eksplisit sama sekali, walau `NULL`. Dengan client MySQL yang benar, dump/restore berjalan sempurna — dibuktikan dengan jumlah baris `users`/`units`/`rental_sessions`/`device_alerts` yang identik sebelum & sesudah restore. Di server production (Ubuntu + `mysql-server` dari APT), `mysqldump`/`mysql` bawaan sudah otomatis dari vendor yang sama — potensi masalah ini spesifik ke mesin dev yang punya lebih dari satu client MySQL/MariaDB terpasang sekaligus.

---

## Prosedur menambah unit baru

1. **Provisioning fisik** (tugas manusia, §14 di bawah): setting TV, pairing HA / flash Tasmota, kabel LAN. DHCP reservation **opsional** untuk jalur Home Assistant — lihat catatan deteksi otomatis di bawah.
2. Tambah unit lewat panel Filament (menu **Unit**, owner-only). Untuk `control_driver = home_assistant`, field **"TV terdeteksi di jaringan"** memuat sendiri semua TV yang sudah ditemukan Home Assistant di WiFi/LAN yang sama — cukup pilih, `Referensi kontrol` terisi otomatis. Tidak perlu tahu IP maupun mengetik entity_id.
   Dari CLI, daftar yang sama bisa dilihat dengan `php artisan units:discover`, lengkap dengan penanda TV mana yang belum dipasangkan ke unit.

   > **Kenapa DHCP reservation tidak wajib untuk jalur HA:** Home Assistant menemukan TV lewat mDNS/SSDP dan mengacu ke `entity_id`, bukan ke IP. IP boleh berubah-ubah dari DHCP; `entity_id` tetap. DHCP reservation tetap **disarankan** untuk server, dan tetap **wajib** untuk plug Tasmota (jalur MQTT mengacu ke topic yang di-flash, dan brokernya perlu alamat yang stabil).
3. Kalau `control_driver=tasmota`: tambah kredensial device di `docker/mosquitto/config/passwordfile` + blok ACL baru di `docker/mosquitto/config/acl.conf` (lihat komentar di file itu), lalu `docker compose -f docker-compose.devices.yml restart mosquitto`.
4. Uji dari dashboard: tombol Power On/Off manual harus benar-benar menyalakan/mematikan TV secara fisik sebelum unit dipakai pelanggan sungguhan.

---

## Akses remote

Panel HANYA di LAN — tanpa port forwarding router apa pun. Akses owner dari luar lokasi lewat **Tailscale** (VPN mesh, bukan buka port publik):

1. Install Tailscale di server & di device owner (laptop/HP).
2. Login ke akun Tailscale yang sama di kedua sisi.
3. Owner akses panel lewat IP Tailscale server (`100.x.x.x`), bukan IP LAN lokal.

Jangan pernah expose port 80/443/8080/3306/1883/8123 langsung ke internet.

---

## Kredensial demo (development/seeder saja)

`php artisan migrate --seed` membuat:

| Role | Email | Password |
|---|---|---|
| Owner | `owner@creativetrees.test` | `password` |
| Kasir | `kasir@creativetrees.test` | `password` |

**Jangan pernah dipakai di production.** Buat user sungguhan lewat menu **Pengguna** (owner-only), lalu **nonaktifkan** akun seeder lewat toggle "Aktif" — akun sengaja tidak bisa dihapus karena `rental_sessions` menyimpan FK ke user (lihat `UserPolicy::delete()`).

---

## 14. Di luar jangkauan AI — tugas manusia

*(dicantumkan apa adanya dari spesifikasi awal proyek — AI tidak pernah mengklaim bisa mengerjakan ini, dan tidak ada kode di repo ini yang berpura-pura menggantikannya)*

- DHCP reservation di router untuk server dan tiap plug Tasmota (catat MAC). *Catatan: untuk TV ber-driver Home Assistant ini tidak lagi wajib — HA menemukannya otomatis lewat mDNS/SSDP dan mengacu ke `entity_id`, bukan IP. Lihat "Prosedur menambah unit baru".*
- Setting di tiap TV: networked standby / power-on-via-network, HDMI-CEC aktif, nama perangkat = kode unit, auto-update firmware & eco timer dimatikan.
- Instalasi Home Assistant + pairing tiap TV (konfirmasi fisik di layar) + pembuatan long-lived access token.
- Flash Tasmota + kredensial & ACL MQTT per plug (jalur Tasmota).
- Penarikan kabel LAN, UPS untuk server+router+switch, isolasi WiFi tamu (client isolation).
- **UAT fisik end-to-end** — sistem dilarang dipakai dengan uang sungguhan sebelum satu siklus penuh (buka sesi → habis → TV mati → bayar → laporan) lolos di hardware asli.
