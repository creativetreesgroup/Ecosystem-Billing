<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    {{-- maximum-scale=1: halaman ini dipakai berdiri di depan TV, dan zoom tak
         sengaja saat menekan tombol besar membuatnya sulit dipakai. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>{{ $title ?? 'Creative Trees Billing Game' }}</title>

    {{-- CSS ditulis langsung di sini, BUKAN kelas Tailwind.
         Proyek ini sengaja nol build frontend, jadi utility Tailwind tidak
         terkompilasi di mana pun — persis jebakan yang sudah pernah menelan
         `mx-auto` di panel (lihat DECISIONS.md). Halaman ini cuma butuh
         belasan aturan; menariknya lewat build hanya untuk itu jauh lebih
         mahal daripada menulisnya. --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        :root {
            --bg: #0a0a0b; --card: #17171a; --line: #2a2a30;
            --text: #f4f4f5; --muted: #8b8b94;
            --accent: #22c55e; --accent-dark: #16a34a; --warn: #f59e0b; --danger: #ef4444;
        }
        body {
            margin: 0; background: var(--bg); color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .kiosk { max-width: 30rem; margin: 0 auto; padding: 1.25rem 1rem 2.5rem; }
        .kiosk-head { text-align: center; margin-bottom: 1.25rem; }
        .kiosk-brand { font-size: .75rem; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin: 0; }
        .kiosk-unit { font-size: 2.75rem; font-weight: 800; margin: .25rem 0 0; line-height: 1; }
        .kiosk-type { color: var(--muted); font-size: .9rem; margin: .35rem 0 0; }

        .card { background: var(--card); border: 1px solid var(--line); border-radius: 1rem; padding: 1.25rem; }
        .card + .card { margin-top: .875rem; }

        .label { font-size: .8rem; color: var(--muted); margin: 0 0 .5rem; }
        .center { text-align: center; }
        .muted { color: var(--muted); font-size: .875rem; }
        .amount { font-size: 2rem; font-weight: 800; color: var(--accent); margin: .25rem 0; text-align: center; }
        .timer { font-size: 2.75rem; font-weight: 800; color: var(--warn); text-align: center; margin: .25rem 0; font-variant-numeric: tabular-nums; }
        .emoji { font-size: 3rem; text-align: center; margin: 0; }
        .title { font-size: 1.25rem; font-weight: 700; text-align: center; margin: .5rem 0 .25rem; }

        /* Target ketuk minimal 56px: dipakai berdiri, sering sambil memegang
           barang, kadang oleh anak-anak. */
        .option {
            display: flex; align-items: center; justify-content: space-between; gap: .75rem;
            min-height: 3.5rem; padding: .875rem 1rem; margin-bottom: .5rem;
            border: 1px solid var(--line); border-radius: .75rem; cursor: pointer;
            transition: border-color .15s, background .15s;
        }
        .option:has(input:checked) { border-color: var(--accent); background: rgba(34,197,94,.1); }
        .option input { position: absolute; opacity: 0; pointer-events: none; }
        .option-name { font-weight: 700; }
        .option-sub { display: block; font-size: .8rem; color: var(--muted); margin-top: .15rem; }
        .option-price { font-weight: 700; color: var(--accent); white-space: nowrap; }

        .methods { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
        .methods .option { justify-content: center; margin: 0; }

        .field {
            width: 100%; min-height: 3.25rem; padding: .875rem 1rem; margin-top: .875rem;
            background: #0f0f11; color: var(--text);
            border: 1px solid var(--line); border-radius: .75rem; font-size: 1rem;
        }
        .field::placeholder { color: #5f5f68; }

        .btn {
            width: 100%; min-height: 3.5rem; margin-top: 1rem; padding: 1rem;
            background: var(--accent-dark); color: #fff; font-size: 1.05rem; font-weight: 700;
            border: 0; border-radius: .75rem; cursor: pointer;
        }
        .btn:active { background: #15803d; }
        .btn:disabled { opacity: .6; cursor: default; }

        .account { background: #0f0f11; border-radius: .75rem; padding: .875rem; text-align: center; margin: .75rem 0; }
        .account-bank { font-weight: 700; font-size: 1.05rem; margin: 0; }
        .account-number { font-weight: 800; font-size: 1.5rem; letter-spacing: .06em; margin: .25rem 0; font-variant-numeric: tabular-nums; }

        .qr { display: block; width: 15rem; margin: .875rem auto 0; background: #fff; padding: .625rem; border-radius: .75rem; }
        .alert { background: rgba(239,68,68,.12); color: #fca5a5; border-radius: .75rem; padding: .75rem; text-align: center; font-size: .875rem; margin-top: .875rem; }
        .error { color: #fca5a5; font-size: .8rem; margin: .35rem 0 0; }
        .foot { text-align: center; color: #4b4b52; font-size: .75rem; margin-top: 1.25rem; }
        [wire\:loading] { display: none; }
    </style>
    @filamentScripts
</head>
<body>
    {{ $slot }}
</body>
</html>
