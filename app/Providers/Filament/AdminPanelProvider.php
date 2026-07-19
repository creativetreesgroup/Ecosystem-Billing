<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => $this->countdownScript(),
            );
    }

    /**
     * Alpine.js bawaan Livewire (§6) — countdown untuk sesi paket
     * (data-ends-at), count-up untuk open play (data-started-at). Murni
     * tampilan client-side; server tetap satu-satunya sumber kebenaran
     * durasi & billing (prinsip arsitektur #2).
     */
    private function countdownScript(): string
    {
        return <<<'HTML'
            <script>
                document.addEventListener('alpine:init', () => {
                    const pad = (n) => String(n).padStart(2, '0');

                    const format = (totalSeconds) => {
                        const h = Math.floor(totalSeconds / 3600);
                        const m = Math.floor((totalSeconds % 3600) / 60);
                        const s = totalSeconds % 60;

                        return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
                    };

                    Alpine.data('countdown', (iso) => ({
                        target: new Date(iso),
                        display: '',
                        tick() {
                            const remaining = Math.max(0, Math.floor((this.target - new Date()) / 1000));
                            this.display = format(remaining);
                        },
                        init() {
                            this.tick();
                            this.interval = setInterval(() => this.tick(), 1000);
                        },
                        destroy() {
                            clearInterval(this.interval);
                        },
                    }));

                    Alpine.data('countup', (iso) => ({
                        startedAt: new Date(iso),
                        display: '',
                        tick() {
                            const elapsed = Math.max(0, Math.floor((new Date() - this.startedAt) / 1000));
                            this.display = format(elapsed);
                        },
                        init() {
                            this.tick();
                            this.interval = setInterval(() => this.tick(), 1000);
                        },
                        destroy() {
                            clearInterval(this.interval);
                        },
                    }));
                });
            </script>
            HTML;
    }
}
