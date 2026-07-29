{{--
    Bukti transfer yang bisa diperbesar.

    Kasir sering harus membaca nominal kecil dan empat digit terakhir rekening
    dari foto layar m-banking. Gambar yang dimuat pas-pasan di dalam modal
    membuat angka itu tidak terbaca, dan menolak pembayaran karena tidak
    terbaca sama saja menahan uang pelanggan tanpa alasan.

    Memakai Alpine — yang memang sudah dibawa Filament — bukan plugin pihak
    ketiga. Alasannya bukan keengganan menambah dependensi, tetapi bahwa
    plugin lightbox mengunci versi Filament: begitu Filament naik versi, panel
    yang memegang uang ikut tertahan menunggu plugin itu menyusul.

    Gaya ditulis INLINE, bukan kelas Tailwind. Proyek ini tidak memakai
    npm/Vite sehingga CSS Filament dipakai apa adanya — kelas utilitas yang
    tidak dipakai Filament sendiri tidak ada di berkas terkompilasinya dan
    diam-diam tidak berefek.
--}}
@php
    $path = $getState();
    $url = filled($path) ? \Illuminate\Support\Facades\Storage::disk('local')->url($path) : null;
@endphp

@if (blank($url))
    <p style="text-align:center;font-size:.875rem;color:rgb(107 114 128)">
        Pelanggan belum mengunggah bukti.
    </p>
@else
    <div
        x-data="{
            z: 1, x: 0, y: 0, drag: false, sx: 0, sy: 0,
            zoom(step) {
                this.z = Math.min(4, Math.max(1, Math.round((this.z + step) * 100) / 100));
                // Kembali ke 1x berarti gambar utuh lagi; geseran lama harus ikut
                // hilang, kalau tidak gambarnya tampak melenceng dengan sendirinya.
                if (this.z === 1) { this.x = 0; this.y = 0; }
            },
            reset() { this.z = 1; this.x = 0; this.y = 0; },
        }"
        x-on:keydown.escape.window="reset()"
    >
        <div
            style="position:relative;overflow:hidden;border-radius:.5rem;height:20rem;
                   background:rgba(0,0,0,.03);box-shadow:inset 0 0 0 1px rgba(17,24,39,.1)"
            x-on:wheel.prevent="zoom($event.deltaY < 0 ? 0.25 : -0.25)"
            x-on:dblclick="z > 1 ? reset() : zoom(1)"
            x-on:mousedown.prevent="drag = true; sx = $event.clientX - x; sy = $event.clientY - y"
            x-on:mousemove.window="if (drag && z > 1) { x = $event.clientX - sx; y = $event.clientY - sy }"
            x-on:mouseup.window="drag = false"
            x-on:mouseleave="drag = false"
            :style="z > 1 ? (drag ? 'cursor:grabbing' : 'cursor:grab') : 'cursor:zoom-in'"
        >
            {{-- transition dimatikan saat menggeser: menganimasikan tiap langkah
                 tetikus membuat gambar terasa tertinggal dari kursor. --}}
            <img
                src="{{ $url }}"
                alt="Bukti transfer"
                draggable="false"
                style="display:block;margin:0 auto;height:100%;width:auto;max-width:100%;
                       object-fit:contain;user-select:none;-webkit-user-drag:none"
                :style="`transform: translate(${x}px, ${y}px) scale(${z});
                         transition: ${drag ? 'none' : 'transform .15s ease-out'}`"
            >
        </div>

        <div style="display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:.625rem">
            @foreach ([['&minus;', 'zoom(-0.25)', 'Perkecil'], ['+', 'zoom(0.25)', 'Perbesar']] as [$glyph, $handler, $label])
                <button
                    type="button"
                    x-on:click="{{ $handler }}"
                    aria-label="{{ $label }}"
                    title="{{ $label }}"
                    style="width:2rem;height:2rem;border:0;border-radius:.375rem;cursor:pointer;
                           font-size:1.05rem;line-height:1;
                           background:rgba(17,24,39,.06);color:rgb(55 65 81)"
                >{!! $glyph !!}</button>
            @endforeach

            <span
                x-text="(Number.isInteger(z) ? z : z.toFixed(2)) + '×'"
                style="min-width:2.75rem;text-align:center;font-size:.75rem;
                       font-variant-numeric:tabular-nums;color:rgb(107 114 128)"
            >1&times;</span>

            <button
                type="button"
                x-on:click="reset()"
                x-bind:disabled="z === 1"
                x-bind:style="z === 1 ? 'opacity:.4;cursor:default' : 'cursor:pointer'"
                style="height:2rem;padding:0 .625rem;border:0;border-radius:.375rem;
                       font-size:.75rem;background:rgba(17,24,39,.06);color:rgb(55 65 81)"
            >Reset</button>
        </div>

        <p style="text-align:center;font-size:.6875rem;color:rgb(156 163 175);margin-top:.375rem">
            Gulir untuk memperbesar &middot; klik-ganda untuk beralih &middot; seret untuk menggeser
        </p>
    </div>
@endif
