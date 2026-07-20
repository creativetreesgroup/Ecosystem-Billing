<?php

namespace App\Providers;

use App\Domain\Customers\Otp\LoggingOtpChannel;
use App\Domain\Customers\Otp\OtpChannel;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // §12: buktikan tabel Filament sudah eager-load relasinya dengan benar
        // — lazy loading yang lolos di sini berarti N+1 nyata di production.
        Model::preventLazyLoading(! $this->app->isProduction());

        $this->useNonNativeDropdownsEverywhere();

        // Penyalur OTP dipilih di satu tempat. Selama belum ada penyedia
        // WhatsApp yang dikonfigurasi, dipakai penyalur log — yang sengaja
        // MENOLAK bekerja di produksi, supaya kios yang tidak bisa dipakai
        // siapa pun ketahuan saat memasang, bukan saat pelanggan pertama
        // sudah berdiri di depan TV.
        $this->app->bind(OtpChannel::class, LoggingOtpChannel::class);
    }

    /**
     * Panel ini dipakai dari HP di meja kasir, bukan cuma dari desktop.
     *
     * Bawaan Filament memakai <select> asli browser. Di mobile itu dirender
     * OLEH SISTEM OPERASI, di luar kotak modal — hasilnya panel melayang di
     * pojok layar, lepas dari form yang memicunya, dan pada modal aksi terlihat
     * seperti panel nyasar. native(false) memakai dropdown Filament sendiri
     * yang tetap berada di dalam alur modal.
     *
     * Diatur satu kali di sini, bukan ditempel per field: kalau per field,
     * setiap Select baru yang ditulis nanti akan mengulang bug yang sama dan
     * baru ketahuan setelah dilihat di HP.
     */
    private function useNonNativeDropdownsEverywhere(): void
    {
        Select::configureUsing(fn (Select $select) => $select->native(false));
        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker->native(false));
        SelectFilter::configureUsing(fn (SelectFilter $filter) => $filter->native(false));
        SelectColumn::configureUsing(fn (SelectColumn $column) => $column->native(false));
    }
}
