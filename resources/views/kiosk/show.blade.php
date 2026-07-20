<x-kiosk.layout :title="$unit->code.' — Creative Trees Billing Game'">
    {{-- wire:poll di komponennya sendiri, bukan di sini: halaman ini statis,
         yang perlu menyegarkan diri adalah status pembayarannya. --}}
    <livewire:kiosk.unit-kiosk :unit="$unit" />
</x-kiosk.layout>
