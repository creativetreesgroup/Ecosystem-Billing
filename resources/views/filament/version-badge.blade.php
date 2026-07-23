@if ($version)
    {{-- Sengaja tenang: ini penanda, bukan pengumuman. Warnanya mengikuti
         tema panel supaya tetap terbaca di mode gelap maupun terang. --}}
    <span
        class="fi-badge fi-size-sm ms-2 self-center rounded-md px-2 py-0.5 text-xs font-medium
               bg-gray-100 text-gray-600 ring-1 ring-gray-200 ring-inset
               dark:bg-white/5 dark:text-gray-300 dark:ring-white/10"
        title="Versi sistem yang sedang berjalan"
    >v{{ $version }}</span>
@endif
