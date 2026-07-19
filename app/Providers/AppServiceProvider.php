<?php

namespace App\Providers;

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
