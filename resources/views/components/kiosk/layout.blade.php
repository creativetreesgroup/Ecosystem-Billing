<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    {{-- maximum-scale=1: dipakai berdiri di depan TV, zoom tak sengaja saat
         menekan tombol besar membuatnya sulit dipakai. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f4f2ef">
    <title>{{ $title ?? 'Creative Trees Billing Game' }}</title>

    {{-- CSS ditulis langsung di sini, BUKAN kelas Tailwind. Proyek ini sengaja
         nol build frontend, jadi utility Tailwind tidak terkompilasi di mana pun.
         Halaman ini cuma butuh satu berkas gaya. --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        /* Tema TERANG, warna merek Creative Trees. Latar terang, aksen cognac,
           kartu saldo gelap (espresso→emerald) di atasnya. Tiap pasangan
           teks/latar dijaga ≥ 4.5:1 (diukur di layar TV & kios). */
        :root {
            --bg: #f4f2ef;            /* off-white hangat */
            --card: #ffffff;
            --border: #e8e2db;
            --border-strong: #d8cec3;
            --ink: #261311;           /* espresso — judul & isi, 17.7:1 di kartu */
            --muted: #6b5d53;         /* abu hangat — 6.3:1 */
            --accent: #b07f45;        /* cognac — GARIS/fokus/pilihan (3.5:1, cukup utk komponen UI) */
            --accent-ink: #8a5e28;    /* cognac tua — TEKS di atas putih/tint (5.7:1) */
            --accent-tint: #f4ebe0;   /* latar pilihan terpilih */
            --primary: #261311;       /* tombol utama: espresso, teks putih 17.7:1 */
            --primary-ink: #ffffff;
            --primary-press: #3b211d;
            --ok: #1c3934;            /* emerald — keberhasilan / uang masuk */
            /* --ok terlalu gelap untuk angka kecil di atas putih: pada 0.95rem ia
               nyaris tak terbedakan dari --ink, jadi 'uang masuk' tidak terbaca
               sekilas. Versi ini tetap emerald tapi jelas hijau (6.4:1 di putih). */
            --ok-strong: #0f6b4f;
            --ok-tint: #e7efeb;

            /* Merah galat SENGAJA di luar palet: peringatan uang tidak boleh
               terlihat seperti dekorasi. #b3261e → 6.5:1 di putih. */
            --danger: #b3261e;
            --danger-tint: #fbeceb;

            --radius: 16px;
            --radius-sm: 12px;
            --shadow: 0 1px 2px rgba(38, 19, 17, .04), 0 12px 32px -12px rgba(38, 19, 17, .12);
        }

        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
            -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility;
            line-height: 1.5;
        }

        .kiosk {
            width: 100%; max-width: 26rem; margin: 0 auto;
            padding: clamp(1.25rem, 4vw, 2rem) clamp(1rem, 4vw, 1.5rem) calc(2.5rem + env(safe-area-inset-bottom));
            min-height: 100dvh; display: flex; flex-direction: column;
        }
        /* Layar masuk & sesi: satu kartu pendek, dipusatkan vertikal seperti
           halaman login Filament. Dasbor & pembayaran yang panjang tetap rata
           atas supaya tidak terpotong. */
        .kiosk--center { justify-content: center; }
        .kiosk--center .stack { flex: 0 1 auto; }

        .kiosk-head { text-align: center; margin-bottom: 1.5rem; }
        .kiosk-brand { font-size: .7rem; letter-spacing: .16em; text-transform: uppercase; color: var(--muted); margin: 0; font-weight: 600; }
        .kiosk-unit { font-size: 2.25rem; font-weight: 800; margin: .35rem 0 0; line-height: 1; letter-spacing: -.02em; }
        .kiosk-type { color: var(--muted); font-size: .9rem; margin: .35rem 0 0; }
        .kiosk-type .pill {
            display: inline-block; padding: .15rem .6rem; border-radius: 999px;
            background: var(--accent-tint); color: var(--accent-ink); font-weight: 700;
            font-size: .75rem; letter-spacing: .04em;
        }

        .card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: var(--radius); padding: clamp(1.25rem, 5vw, 1.75rem);
            box-shadow: var(--shadow);
        }
        .card + .card { margin-top: 1rem; }

        .stack { flex: 1; }

        h2.card-title { font-size: 1.35rem; font-weight: 800; margin: 0 0 .35rem; text-align: center; letter-spacing: -.01em; }
        .card-sub { color: var(--muted); font-size: .9rem; text-align: center; margin: 0 0 1.25rem; }
        .card-sub strong { color: var(--ink); }
        .label { font-size: .8rem; font-weight: 600; color: var(--muted); margin: 0 0 .625rem; }
        .center { text-align: center; }
        .muted { color: var(--muted); font-size: .875rem; }

        .amount { font-size: 2.5rem; font-weight: 800; margin: .25rem 0; text-align: center; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
        .amount.neg { color: var(--danger); }
        .timer { font-size: 2.75rem; font-weight: 800; color: var(--ink); text-align: center; margin: .25rem 0; font-variant-numeric: tabular-nums; letter-spacing: -.01em; }
        .title { font-size: 1.25rem; font-weight: 800; text-align: center; margin: .25rem 0 .5rem; }

        /* Input */
        .field {
            width: 100%; min-height: 3.25rem; padding: .875rem 1rem;
            background: #fff; color: var(--ink);
            border: 1.5px solid var(--border-strong); border-radius: var(--radius-sm);
            font-size: 1.05rem; transition: border-color .15s, box-shadow .15s;
            -webkit-appearance: none; appearance: none;
        }
        .field::placeholder { color: #a99e93; }
        .field:focus { outline: 0; border-color: var(--accent); box-shadow: 0 0 0 4px rgba(176, 127, 69, .14); }
        .field + .field { margin-top: .75rem; }
        .field-affix { position: relative; }
        .field-affix .prefix {
            position: absolute; left: 1rem; top: 50%; transform: translateY(-50%);
            width: 1.25rem; height: 1.25rem; color: var(--muted); pointer-events: none; display: flex;
        }
        .field-affix .prefix svg { width: 100%; height: 100%; }
        .field-affix .field { padding-left: 3rem; }

        .icon-badge {
            width: 3.5rem; height: 3.5rem; border-radius: 999px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--accent-tint); color: var(--accent-ink); margin: 0 auto .75rem;
        }
        .icon-badge svg { width: 1.75rem; height: 1.75rem; }

        /* Tombol — utama espresso, teks putih; sekunder putih bergaris. */
        .btn {
            width: 100%; min-height: 3.25rem; padding: 1rem; margin-top: 1rem;
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            background: var(--primary); color: var(--primary-ink);
            font-size: 1.05rem; font-weight: 700; font-family: inherit;
            border: 0; border-radius: var(--radius-sm); cursor: pointer;
            transition: background .15s, transform .05s;
        }
        .btn:active { background: var(--primary-press); transform: translateY(1px); }
        .btn:disabled { opacity: .55; cursor: default; }
        .btn-ghost { background: #fff; color: var(--accent-ink); border: 1.5px solid var(--border-strong); }
        .btn-ghost:active { background: var(--accent-tint); }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-block-gap { margin-top: .75rem; }

        .linkish {
            display: block; width: 100%; margin-top: 1rem; padding: .625rem;
            background: none; border: 0; color: var(--muted); font-size: .9rem; cursor: pointer; font-family: inherit;
        }
        .linkish b { color: var(--accent-ink); }

        /* Pilihan */
        .options { display: flex; flex-direction: column; gap: .5rem; }
        .option {
            display: flex; align-items: center; justify-content: space-between; gap: .75rem;
            min-height: 3.5rem; padding: .875rem 1rem;
            border: 1.5px solid var(--border); border-radius: var(--radius-sm);
            cursor: pointer; background: #fff;
            transition: border-color .15s, background .15s;
        }
        .option:has(input:checked) { border-color: var(--accent); background: var(--accent-tint); }
        .option input { position: absolute; opacity: 0; pointer-events: none; }
        .option-name { font-weight: 700; }
        .option-sub { display: block; font-size: .8rem; color: var(--muted); margin-top: .1rem; font-weight: 400; }
        .option-price { font-weight: 800; color: var(--ink); white-space: nowrap; }
        .option-price .per { font-weight: 600; color: var(--muted); font-size: .8rem; }
        .option-off { opacity: .5; }

        /* Pilih cara main — Open Play (kartu hero berikon) + paket (grid 2 kolom).
           Sorotan HANYA saat terpilih; sebelumnya Open Play selalu bernuansa
           cognac sehingga terlihat seolah sudah dipilih. */
        .play-open {
            display: flex; align-items: center; gap: .875rem;
            padding: 1rem; border-radius: var(--radius-sm);
            border: 1.5px solid var(--border-strong); background: #fff; cursor: pointer;
            transition: border-color .15s, background .15s;
        }
        .play-open input { position: absolute; opacity: 0; pointer-events: none; }
        .play-open:has(input:checked) { border-color: var(--accent); background: var(--accent-tint); }
        .po-ic {
            width: 2.9rem; height: 2.9rem; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: var(--accent-tint); color: var(--accent-ink);
        }
        .play-open:has(input:checked) .po-ic { background: #fff; }
        .po-ic svg { width: 1.5rem; height: 1.5rem; }
        .po-body { flex: 1; display: flex; flex-direction: column; }
        .po-title { font-weight: 800; font-size: 1.05rem; }
        .po-sub { font-size: .8rem; color: var(--muted); }
        .po-rate { font-weight: 800; white-space: nowrap; text-align: right; }
        .po-rate .per { font-weight: 600; color: var(--muted); font-size: .75rem; }

        .play-divider { display: flex; align-items: center; gap: .75rem; margin: 1rem 0 .75rem; color: var(--muted); font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .play-divider::before, .play-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

        .play-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
        .play-pkg {
            position: relative; display: flex; flex-direction: column;
            padding: 1rem .875rem; border-radius: var(--radius-sm);
            border: 1.5px solid var(--border-strong); background: #fff; cursor: pointer;
            transition: border-color .15s, background .15s;
        }
        .play-pkg input { position: absolute; opacity: 0; pointer-events: none; }
        .play-pkg:has(input:checked) { border-color: var(--accent); background: var(--accent-tint); }
        .play-pkg.play-off { opacity: .5; }
        .pg-dur { font-weight: 800; font-size: 1.15rem; letter-spacing: -.01em; }
        .pg-min { font-size: .72rem; color: var(--muted); margin-top: .1rem; }
        .pg-price { font-weight: 800; color: var(--ink); margin-top: .625rem; }

        /* Metode bayar: tombol bulat ikon + label di bawah. Terpilih → lingkaran
           terisi cognac tint bergaris. */
        .methods-round { display: flex; justify-content: center; gap: 2rem; margin-top: 1rem; }
        .method-r {
            display: flex; flex-direction: column; align-items: center; gap: .5rem;
            cursor: pointer; font-size: .85rem; font-weight: 700; color: var(--ink);
        }
        .method-r input { position: absolute; opacity: 0; pointer-events: none; }
        .method-r .ic {
            width: 3.75rem; height: 3.75rem; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: var(--card); border: 1.5px solid var(--border-strong); color: var(--accent-ink);
            transition: background .15s, border-color .15s, transform .05s;
        }
        .method-r:active .ic { transform: translateY(1px); }
        .method-r:has(input:checked) .ic { background: var(--accent-tint); border-color: var(--accent); }
        .method-r .ic svg { width: 1.6rem; height: 1.6rem; }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
        .grid-2 .option, .grid-3 .option { justify-content: center; text-align: center; min-height: 3rem; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; }
        @media (max-width: 360px) { .grid-3 { grid-template-columns: 1fr 1fr; } }

        /* OTP */
        .otp { display: flex; align-items: center; justify-content: center; gap: .5rem; margin: .5rem 0 1rem; }
        .otp input {
            width: 3rem; height: 3.5rem; text-align: center;
            font-size: 1.5rem; font-weight: 800; color: var(--ink);
            border: 1.5px solid var(--border-strong); border-radius: var(--radius-sm);
            background: #fff; font-variant-numeric: tabular-nums;
            -webkit-appearance: none; appearance: none; transition: border-color .15s, box-shadow .15s;
        }
        .otp input:focus { outline: 0; border-color: var(--accent); box-shadow: 0 0 0 4px rgba(176, 127, 69, .14); }
        .otp .otp-dash { color: var(--border-strong); font-weight: 800; }
        @media (max-width: 360px) { .otp input { width: 2.5rem; height: 3rem; font-size: 1.25rem; } .otp { gap: .35rem; } }

        /* Kotak kode tidak punya placeholder, jadi label inilah satu-satunya
           yang memberi tahu enam kotak itu untuk apa. */
        .field-label { text-align: center; font-size: .85rem; color: var(--muted); margin: .75rem 0 -.25rem; }

        .resend { text-align: center; font-size: .875rem; color: var(--muted); margin-top: .25rem; }
        .resend button { background: none; border: 0; color: var(--accent-ink); font-weight: 700; cursor: pointer; font-family: inherit; font-size: .875rem; padding: 0; }
        .resend button:disabled { color: var(--muted); font-weight: 400; cursor: default; }

        /* Layar pembayaran — konsisten: lencana ikon, judul, nominal besar. */
        .pay-card { text-align: center; }
        .pay-amount { font-size: 2.25rem; font-weight: 800; margin: .5rem 0 1rem; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
        .icon-badge-ok { background: var(--ok-tint); color: var(--ok); }

        .account { background: var(--accent-tint); border-radius: var(--radius-sm); padding: 1.1rem 1rem; text-align: center; margin: 1rem 0; }
        .account-bank { font-weight: 700; font-size: .8rem; letter-spacing: .12em; text-transform: uppercase; color: var(--accent-ink); margin: 0; }
        .account-number { font-weight: 800; font-size: 1.7rem; letter-spacing: .1em; margin: .35rem 0; font-variant-numeric: tabular-nums; }
        .account-holder { color: var(--muted); font-size: .85rem; margin: .25rem 0 0; }
        .account-holder span { font-weight: 800; color: var(--accent-ink); letter-spacing: .04em; margin-right: .15rem; }

        .qr { display: block; width: 15rem; max-width: 70vw; margin: 0 auto; background: #fff; padding: .625rem; border: 1px solid var(--border); border-radius: var(--radius-sm); }

        /* Pemilih file modern — input asli disembunyikan, tombolnya kartu berikon
           kamera yang menampilkan nama file setelah dipilih. */
        .upload {
            display: flex; align-items: center; gap: .875rem; text-align: left;
            margin-top: 1rem; padding: 1rem; cursor: pointer;
            border: 1.5px dashed var(--border-strong); border-radius: var(--radius-sm);
            background: #fff; transition: border-color .15s, background .15s;
        }
        .upload.has-file { border-style: solid; border-color: var(--accent); background: var(--accent-tint); }
        .upload input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .upload-ic { width: 2.75rem; height: 2.75rem; border-radius: 12px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: var(--accent-tint); color: var(--accent-ink); }
        .upload.has-file .upload-ic { background: #fff; }
        .upload-ic svg { width: 1.4rem; height: 1.4rem; }
        .upload-text { display: flex; flex-direction: column; min-width: 0; }
        .upload-text b { font-size: .95rem; font-weight: 700; }
        .upload-name { font-size: .78rem; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .alert { background: var(--danger-tint); color: var(--danger); border-radius: var(--radius-sm); padding: .75rem 1rem; text-align: center; font-size: .9rem; margin-top: 1rem; }
        .notice { background: var(--ok-tint); color: var(--ok); border-radius: var(--radius-sm); padding: .75rem 1rem; text-align: center; font-size: .9rem; margin-top: 1rem; font-weight: 600; }
        .error { color: var(--danger); font-size: .8rem; margin: .4rem 0 0; }

        .foot { text-align: center; color: var(--muted); opacity: .7; font-size: .75rem; margin-top: 1.5rem; }

        /* Sapaan + KARTU saldo gelap (espresso→emerald) di atas dasbor terang. */
        .greet { font-size: 1.15rem; color: var(--muted); margin: 0 0 .875rem; padding: 0 .25rem; }
        .greet b { color: var(--ink); font-weight: 800; }

        .balance-card {
            position: relative; overflow: hidden; border: 0; color: #f9edf0;
            background: linear-gradient(135deg, #261311 0%, #1c3934 100%);
            box-shadow: 0 16px 36px -12px rgba(38, 19, 17, .45);
            padding: 1.4rem 1.5rem 1.3rem;
        }
        .balance-card::after {
            content: ''; position: absolute; top: -45%; right: -25%;
            width: 75%; height: 150%; border-radius: 50%;
            background: radial-gradient(circle, rgba(195, 149, 91, .38), transparent 68%);
            pointer-events: none;
        }
        .balance-card > * { position: relative; z-index: 1; }
        .vc-top { display: flex; align-items: center; justify-content: space-between; }
        .vc-brand { font-size: .7rem; letter-spacing: .16em; text-transform: uppercase; font-weight: 700; color: rgba(249, 237, 240, .9); }
        .vc-wifi { width: 1.4rem; height: 1.4rem; color: rgba(249, 237, 240, .7); display: flex; }
        /* Diputar 90° supaya gelombangnya menghadap KANAN — lambang contactless/
           NFC pada kartu sungguhan, bukan ikon wifi yang menghadap atas. */
        .vc-wifi svg { width: 100%; height: 100%; transform: rotate(90deg); }
        .vc-chip {
            width: 2.4rem; height: 1.8rem; border-radius: 6px; margin: 1.1rem 0 0;
            background: linear-gradient(135deg, #d8b98a, #b07f45);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .25);
        }
        .vc-label { font-size: .65rem; letter-spacing: .14em; text-transform: uppercase; color: rgba(230, 200, 183, .85); margin: 1rem 0 .1rem; }
        /* Nominalnya SELALU satu baris. Tanpa nowrap, saldo panjang (mis. isi
           berkali-kali) membungkus jadi dua baris dan mendorong seluruh isi
           kartu ke bawah — tinggi kartu ikut berubah dan tata letaknya rusak.
           Ukuran font disetel per panjang angka dari sisi server (inline style
           di bawah) supaya angka panjang mengecil, bukan turun. */
        .vc-balance { font-size: clamp(2rem, 8vw, 2.5rem); font-weight: 800; letter-spacing: -.02em; margin: 0; font-variant-numeric: tabular-nums; line-height: 1.05; white-space: nowrap; overflow: hidden; }
        .vc-balance.neg { color: #ffb4a8; }
        .vc-number { font-size: 1.05rem; letter-spacing: .14em; margin: 1rem 0 0; color: rgba(249, 237, 240, .92); font-variant-numeric: tabular-nums; }
        .vc-bottom { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; margin-top: .85rem; }
        .vc-name { font-size: .82rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .vc-meta { display: flex; gap: 1.1rem; flex-shrink: 0; }
        .vc-meta .k { font-size: .5rem; letter-spacing: .12em; text-transform: uppercase; color: rgba(230, 200, 183, .75); display: block; margin-bottom: .1rem; }
        .vc-meta .v { font-size: .82rem; font-weight: 700; }
        @media (max-width: 340px) { .vc-number { font-size: .95rem; letter-spacing: .08em; } }

        /* Menu cepat — tombol BULAT ikon-saja. Yang aktif diisi espresso dengan
           ikon putih (tegas), yang lain putih bergaris dengan ikon cognac. */
        .quick { display: flex; justify-content: center; gap: 1.25rem; margin: 1.25rem 0; }
        .quick-tile {
            width: 3.5rem; height: 3.5rem; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: var(--card); border: 1.5px solid var(--border-strong); box-shadow: var(--shadow);
            color: var(--accent-ink); cursor: pointer;
            transition: background .15s, border-color .15s, color .15s, transform .05s;
        }
        .quick-tile:active { transform: translateY(1px); }
        .quick-tile.is-active { background: var(--primary); border-color: var(--primary); color: #fff; }
        .quick-tile svg { width: 1.45rem; height: 1.45rem; }
        .quick-tile.quick-off { opacity: .4; cursor: default; }

        /* Kontrol "tampilkan berapa" di Riwayat — input angka manual, default 5. */
        .perpage { font-size: .8rem; color: var(--muted); display: inline-flex; align-items: center; gap: .4rem; }
        .perpage-input {
            width: 3rem; min-height: 2rem; padding: .25rem .4rem; text-align: center;
            background: #fff; color: var(--ink); font-family: inherit; font-size: .9rem; font-weight: 700;
            border: 1.5px solid var(--border-strong); border-radius: 8px; -webkit-appearance: none; appearance: none;
        }
        .perpage-input:focus { outline: 0; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(176, 127, 69, .14); }

        .pager { display: flex; align-items: center; justify-content: center; gap: 1rem; margin-top: .875rem; }
        .pager-info { font-size: .85rem; color: var(--muted); font-weight: 600; font-variant-numeric: tabular-nums; }
        .pager-btn {
            width: 2.25rem; height: 2.25rem; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: #fff; border: 1.5px solid var(--border-strong); color: var(--ink); cursor: pointer;
        }
        .pager-btn svg { width: 1.1rem; height: 1.1rem; }
        .pager-btn:disabled { opacity: .35; cursor: default; }

        /* Daftar transaksi (dari buku besar saldo). */
        .section-title { display: flex; align-items: center; justify-content: space-between; margin: 0 0 .25rem; }
        .section-title h3 { font-size: 1.1rem; font-weight: 800; margin: 0; }
        .tx { display: flex; align-items: center; gap: .875rem; padding: .7rem 0; }
        .tx + .tx { border-top: 1px solid var(--border); }
        .tx-icon { width: 2.5rem; height: 2.5rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .tx-icon svg { width: 1.15rem; height: 1.15rem; }
        .tx-in { background: var(--ok-tint); color: var(--ok); }
        .tx-out { background: var(--accent-tint); color: var(--accent-ink); }
        .tx-body { flex: 1; min-width: 0; }
        .tx-title { font-weight: 700; font-size: .95rem; margin: 0; display: flex; align-items: center; gap: .5rem; }
        /* Badge metode bayar pada baris pemasukan — dari mana uangnya masuk. */
        .tx-badge {
            font-size: .62rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase;
            padding: .1rem .45rem; border-radius: 999px;
            background: var(--accent-tint); color: var(--accent-ink);
        }
        .tx-sub { color: var(--muted); font-size: .78rem; margin: .1rem 0 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tx-amt { font-weight: 800; font-size: .95rem; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .tx-amt.in { color: var(--ok-strong); }
        /* Waktu pindah ke kolom kanan di bawah nominal. Sebelumnya ia didempet
           di belakang keterangan pada satu baris, dan justru WAKTUNYA yang
           kena elipsis — padahal itu yang dicari mata saat menelusuri riwayat. */
        .pay-fee { text-align: center; font-size: .78rem; color: var(--muted); margin: .3rem 0 0; }
        .tx-right { display: flex; flex-direction: column; align-items: flex-end; gap: .1rem; flex-shrink: 0; }
        .tx-time { font-size: .72rem; color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
        .tx-empty { color: var(--muted); font-size: .875rem; text-align: center; padding: .5rem 0; }

        /* Papan angka nominal isi saldo — angka besar + tombol 3×4. */
        .pad-amount { font-size: clamp(2rem, 9vw, 2.6rem); font-weight: 800; text-align: center; margin: .5rem 0 .35rem; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
        .pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; }
        .pad-key {
            min-height: 3.25rem; border-radius: var(--radius-sm);
            background: #fff; border: 1.5px solid var(--border); color: var(--ink);
            font-family: inherit; font-size: 1.3rem; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; justify-content: center; transition: background .1s, transform .05s;
        }
        .pad-key:active { background: var(--accent-tint); transform: translateY(1px); }
        .pad-key svg { width: 1.4rem; height: 1.4rem; color: var(--muted); }

        /* Konfirmasi pembelian — sheet menutupi layar, muncul dari bawah. */
        .modal-backdrop {
            position: fixed; inset: 0; z-index: 50;
            background: rgba(38, 19, 17, .55);
            display: flex; align-items: flex-end; justify-content: center;
            padding: 1rem; animation: fade .15s ease;
        }
        @keyframes fade { from { opacity: 0; } }
        .modal {
            width: 100%; max-width: 24rem;
            background: var(--card); border-radius: var(--radius);
            padding: 1.5rem clamp(1.25rem, 5vw, 1.75rem) calc(1.5rem + env(safe-area-inset-bottom));
            box-shadow: 0 -8px 40px -8px rgba(38, 19, 17, .35); animation: sheet .2s ease;
        }
        @keyframes sheet { from { transform: translateY(24px); opacity: .5; } }
        .confirm-rows { margin: 1rem 0 .25rem; }
        .confirm-rows > div { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .65rem 0; font-size: .95rem; }
        .confirm-rows > div + div { border-top: 1px solid var(--border); }
        .confirm-rows span { color: var(--muted); }
        .confirm-rows b { font-weight: 800; text-align: right; }
        .confirm-total b { font-size: 1.1rem; }

        [wire\:loading] { display: none; }
        .spin { display: inline-block; width: 1rem; height: 1rem; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Pesan makanan & minuman ─────────────────────────────────────
           Barang yang masuk keranjang memakai sorotan YANG SAMA dengan paket
           terpilih (accent-tint + garis cognac), supaya "terpilih" berarti satu
           hal yang sama di seluruh kios — bukan bahasa visual baru. */
        .menu-sec { margin-top: 1.25rem; }
        .menu-sec-head { display: flex; align-items: center; gap: .625rem; margin-bottom: .625rem; }
        .menu-sec-name { font-size: .7rem; letter-spacing: .16em; text-transform: uppercase; font-weight: 700; color: var(--muted); white-space: nowrap; }
        .menu-sec-rule { flex: 1; height: 1px; background: var(--border); }
        .menu-sec-count { font-size: .7rem; color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; }

        .menu-item {
            display: flex; align-items: center; gap: .875rem;
            padding: .75rem .875rem; border-radius: var(--radius-sm);
            border: 1.5px solid var(--border); background: #fff;
            transition: border-color .15s, background .15s;
        }
        .menu-item + .menu-item { margin-top: .5rem; }
        .menu-item.is-picked { border-color: var(--accent); background: var(--accent-tint); }
        .menu-item-body { flex: 1; min-width: 0; }
        .menu-item-name { font-weight: 700; font-size: .98rem; line-height: 1.25; }
        .menu-item-price { font-size: .82rem; color: var(--muted); font-variant-numeric: tabular-nums; margin-top: .1rem; }
        .menu-item.is-picked .menu-item-price { color: var(--accent-ink); font-weight: 600; }

        /* Stepper: hanya "+" saat kosong, melebar jadi −/jumlah/+ saat dipakai.
           Menekan kebisingan visual di daftar panjang; pelebarannya sendiri yang
           jadi umpan balik. 2.75rem: dipakai berdiri sambil memegang HP. */
        .stepper { display: flex; align-items: center; gap: .25rem; flex-shrink: 0; }
        .step-btn {
            width: 2.75rem; height: 2.75rem; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: #fff; border: 1.5px solid var(--border-strong);
            color: var(--ink); font-size: 1.35rem; font-weight: 700; line-height: 1;
            font-family: inherit; cursor: pointer;
            transition: background .15s, border-color .15s, transform .05s;
        }
        .step-btn:active { transform: translateY(1px); background: var(--accent-tint); }
        .step-add { border-color: var(--accent); color: var(--accent-ink); }
        .menu-item.is-picked .step-add { background: #fff; }
        .step-qty { min-width: 1.75rem; text-align: center; font-weight: 800; font-variant-numeric: tabular-nums; font-size: 1.05rem; }

        /* Baki pesanan — SATU-SATUNYA elemen gelap di daftar ini, sengaja:
           totalnya harus terlihat terus tanpa menggulir balik ke atas. Bahannya
           sama dengan kartu saldo, jadi terasa satu keluarga. */
        .tray {
            position: sticky; bottom: .75rem; z-index: 5;
            display: flex; align-items: center; gap: .875rem;
            margin-top: 1.25rem; padding: .875rem 1rem;
            background: var(--primary); color: var(--primary-ink);
            border-radius: var(--radius-sm);
            box-shadow: 0 10px 30px -10px rgba(38, 19, 17, .55);
            animation: tray-in .18s ease;
        }
        @keyframes tray-in { from { transform: translateY(8px); opacity: 0; } }
        .tray-body { flex: 1; min-width: 0; }
        .tray-count { font-size: .75rem; opacity: .75; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tray-total { font-weight: 800; font-size: 1.15rem; font-variant-numeric: tabular-nums; letter-spacing: -.01em; }
        .tray-btn {
            flex-shrink: 0; min-height: 2.75rem; padding: 0 1.1rem;
            background: #fff; color: var(--primary); border: 0; border-radius: 999px;
            font-family: inherit; font-size: .95rem; font-weight: 800; cursor: pointer; white-space: nowrap;
            transition: opacity .15s, transform .05s;
        }
        .tray-btn:active { transform: translateY(1px); opacity: .9; }
        .tray-btn:disabled { opacity: .6; cursor: default; }

        /* Pesanan yang sedang dikerjakan: titik status memakai warna "berhasil"
           yang di kios ini sudah berarti "beres / uang aman". */
        .order-live { display: flex; align-items: center; gap: .75rem; padding: .75rem .875rem; border-radius: var(--radius-sm); background: var(--ok-tint); }
        .order-live + .order-live { margin-top: .5rem; }
        .order-dot { width: .5rem; height: .5rem; border-radius: 50%; background: var(--ok); flex-shrink: 0; }
        .order-live-body { flex: 1; min-width: 0; }
        .order-live-items { font-size: .9rem; font-weight: 600; color: var(--ink); }
        .order-live-state { font-size: .75rem; color: var(--ok); font-weight: 700; }
        .order-live-total { font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; }

        .menu-empty { text-align: center; color: var(--muted); font-size: .9rem; padding: 1.75rem 0; line-height: 1.6; }

        /* ── Dapur tutup / istirahat ─────────────────────────────────────
           Bukan pesan galat: pelanggan tidak melakukan kesalahan apa pun, jadi
           nadanya tenang — bukan merah. Yang harus terbaca lebih dulu dari
           jarak satu meter adalah KEADAANNYA, baru jam kembalinya. */
        .kitchen-shut { text-align: center; padding: 2.25rem 1.25rem; border-radius: 14px;
            background: color-mix(in srgb, var(--ink) 4%, transparent);
            border: 1px dashed color-mix(in srgb, var(--ink) 18%, transparent); }
        .kitchen-shut-icon { display: inline-flex; width: 3rem; height: 3rem; align-items: center; justify-content: center;
            border-radius: 50%; margin-bottom: .85rem; color: var(--accent);
            background: color-mix(in srgb, var(--accent) 12%, transparent); }
        .kitchen-shut-icon svg { width: 1.65rem; height: 1.65rem; }
        .kitchen-shut-title { font-size: 1.15rem; font-weight: 700; color: var(--ink); margin: 0 0 .35rem; }
        .kitchen-shut-note { font-size: .92rem; color: var(--muted); line-height: 1.65; margin: 0; max-width: 30ch;
            margin-inline: auto; }
        /* Istirahat itu sementara — semburat hangat memisahkannya dari tutup. */
        .kitchen-shut--break { background: color-mix(in srgb, var(--accent) 7%, transparent);
            border-color: color-mix(in srgb, var(--accent) 32%, transparent); }

        /* ── Lantai aksesibilitas ────────────────────────────────────────
           Sebelumnya HANYA input yang punya cincin fokus — tombol sama sekali
           tak terlihat saat dinavigasi keyboard. */
        .btn:focus-visible, .btn-ghost:focus-visible, .quick-tile:focus-visible,
        .pager-btn:focus-visible, .step-btn:focus-visible, .tray-btn:focus-visible,
        .linkish:focus-visible, .play-open:has(input:focus-visible), .play-pkg:has(input:focus-visible) {
            outline: 3px solid var(--accent); outline-offset: 2px;
        }

        /* Hormati pengguna yang mematikan animasi di sistemnya. */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .01ms !important; animation-iteration-count: 1 !important;
                transition-duration: .01ms !important; scroll-behavior: auto !important;
            }
        }
    </style>
    @filamentScripts
    {{-- Dorongan realtime "pembayaran lunas" ke HP pelanggan. Progressive
         enhancement: kalau Echo/WebSocket tak ada, wire:poll tetap menyusul.
         leave() saat destroy mencegah langganan menumpuk / bocor. --}}
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('kioskRealtime', (customerId) => ({
                channel: 'customer.' + customerId,
                init() {
                    if (! window.Echo) { return; }
                    window.Echo.private(this.channel)
                        .listen('.payment.settled', () => this.$wire.refreshStatus());
                },
                destroy() {
                    if (window.Echo) { window.Echo.leave(this.channel); }
                },
            }));
        });
    </script>
</head>
<body>
    {{ $slot }}
</body>
</html>
