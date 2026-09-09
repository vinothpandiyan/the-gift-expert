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
use Filament\Widgets\AccountWidget;
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
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
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
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => <<<'HTML'
                    <style>
                        .fi-header { flex-wrap: wrap; align-items: flex-start; row-gap: 0.75rem; }
                        .fi-header-heading { min-width: 12rem; overflow-wrap: break-word; }
                        .fi-header-actions-ctn,
                        .fi-header .fi-ac { flex-wrap: wrap; max-width: 100%; justify-content: flex-end; }
                        [data-classification-review] { min-width: 0; overflow-wrap: break-word; }
                        [data-classification-review] p,
                        [data-classification-review] li,
                        [data-classification-review] td,
                        [data-classification-review] span { overflow-wrap: break-word; word-break: normal; }
                        [data-proposal-vs-applied] {
                            display: grid;
                            grid-template-columns: minmax(0, 1fr);
                            gap: 1rem;
                            min-width: 0;
                        }
                        @media (min-width: 1024px) {
                            [data-proposal-vs-applied] {
                                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                            }
                        }
                        .fi-fo-select { min-width: 0; }
                    </style>
                HTML,
            );
    }
}
