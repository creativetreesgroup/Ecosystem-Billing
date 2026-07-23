@props([
    /** Nama properti Livewire yang diisi (mis. 'code' atau 'pin'). */
    'model',
    /** Method Livewire yang dipanggil begitu kotak ke-6 terisi. Null = tidak auto-kirim. */
    'submit' => null,
    /** PIN disembunyikan; kode OTP tidak. Lihat catatan di bawah. */
    'masked' => false,
    'autofocus' => false,
    'wireKey' => null,
])

{{--
    Enam kotak angka: auto-maju, backspace mundur, tempel 6 angka sekaligus.

    Dipakai bersama oleh kode OTP DAN PIN. Sebelumnya PIN memakai satu kotak
    teks panjang sementara OTP memakai enam kotak — dua cara memasukkan enam
    angka di layar yang sama, dan yang satu terasa jelas lebih kasar. Menyalin
    Alpine-nya ke tiga tempat berarti tiga salinan yang bisa menyimpang
    diam-diam; jadi tinggal satu di sini.

    PIN tetap DISAMARKAN. Bentuknya sama dengan OTP, tapi isinya tidak: kode
    OTP sekali pakai dan mati dalam hitungan menit, sedangkan PIN dipakai
    berulang — dan kios ini berdiri di ruangan terbuka tempat orang lain bisa
    ikut melihat layarnya.
--}}
<div class="otp" @if ($wireKey) wire:key="{{ $wireKey }}" @endif
     x-data="{
        d: ['','','','','',''],
        clear() {
            for (let i = 0; i < 6; i++) { this.d[i] = ''; this.$refs['d'+i].value = ''; }
            this.$refs.d0.focus();
        },
        sync() {
            const value = this.d.join('');
            $wire.set('{{ $model }}', value, false);
            @if ($submit)
                if (value.length === 6) { $wire.{{ $submit }}(); }
            @endif
        },
        input(i, e) {
            let v = e.target.value.replace(/[^0-9]/g, '');
            if (v.length > 1) { this.spread(v); return; }
            this.d[i] = v; e.target.value = v;
            if (v && i < 5) this.$refs['d'+(i+1)].focus();
            this.sync();
        },
        key(i, e) {
            if (e.key === 'Backspace' && !this.d[i] && i > 0) { this.$refs['d'+(i-1)].focus(); }
        },
        spread(text) {
            const ds = text.replace(/[^0-9]/g, '').slice(0, 6).split('');
            for (let i = 0; i < 6; i++) { this.d[i] = ds[i] || ''; this.$refs['d'+i].value = this.d[i]; }
            this.$refs['d'+Math.min(ds.length, 5)].focus();
            this.sync();
        }
     }"
     {{-- Server mengosongkan propertinya setelah kode/PIN salah. Tanpa ini
          kotaknya tetap penuh angka yang baru saja ditolak, dan pelanggan
          harus menghapusnya satu per satu sebelum bisa mencoba lagi. --}}
     x-effect="if ($wire.{{ $model }} === '' && d.join('') !== '') clear()">
    @foreach (range(0, 5) as $i)
        @if ($i === 3)<span class="otp-dash">&ndash;</span>@endif
        <input type="{{ $masked ? 'password' : 'text' }}" inputmode="numeric" maxlength="1"
               autocomplete="{{ $masked ? 'off' : 'one-time-code' }}"
               aria-label="Angka ke-{{ $i + 1 }}"
               x-ref="d{{ $i }}" @if ($autofocus && $i === 0) autofocus @endif
               @input="input({{ $i }}, $event)" @keydown="key({{ $i }}, $event)"
               @paste.prevent="spread($event.clipboardData.getData('text'))">
    @endforeach
</div>
