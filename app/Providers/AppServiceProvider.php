<?php

namespace App\Providers;

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
    }
}
