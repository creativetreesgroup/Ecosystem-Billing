<x-filament-panels::page>
    <x-filament::section heading="Filter">
        {{ $this->form }}
    </x-filament::section>

    <x-filament::section heading="Ringkasan">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Jumlah Sesi</p>
                <p class="text-2xl font-bold text-gray-950 dark:text-white">{{ $this->getTotalSessions() }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Total Pendapatan</p>
                <p class="text-2xl font-bold text-gray-950 dark:text-white">{{ $this->getTotalRevenue() }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Jam Tersibuk</p>
                <p class="text-2xl font-bold text-gray-950 dark:text-white">{{ $this->getBusiestHour() ?? '-' }}</p>
            </div>
        </div>
    </x-filament::section>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-filament::section heading="Pendapatan per Metode Bayar">
            <ul class="space-y-2">
                @forelse ($this->getRevenueByPaymentMethod() as $row)
                    <li class="flex items-center justify-between text-sm">
                        <span>{{ $row['label'] }} ({{ $row['count'] }} sesi)</span>
                        <span class="font-medium">{{ $row['revenue'] }}</span>
                    </li>
                @empty
                    <li class="text-sm text-gray-500 dark:text-gray-400">Tidak ada data pada rentang ini.</li>
                @endforelse
            </ul>
        </x-filament::section>

        <x-filament::section heading="Pendapatan per Tipe Unit">
            <ul class="space-y-2">
                @forelse ($this->getRevenueByUnitType() as $row)
                    <li class="flex items-center justify-between text-sm">
                        <span>{{ $row['label'] }} ({{ $row['count'] }} sesi)</span>
                        <span class="font-medium">{{ $row['revenue'] }}</span>
                    </li>
                @empty
                    <li class="text-sm text-gray-500 dark:text-gray-400">Tidak ada data pada rentang ini.</li>
                @endforelse
            </ul>
        </x-filament::section>
    </div>

    <x-filament::section heading="Rincian Harian">
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
