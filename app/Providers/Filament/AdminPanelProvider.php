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
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => view('filament.hooks.frontend-link')->render(),
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => <<<'HTML'
                    <style>
                        .fi-header { flex-wrap: wrap; align-items: flex-start; row-gap: 0.75rem; }
                        .fi-header-heading { min-width: 12rem; overflow-wrap: break-word; }
                        .fi-header-actions-ctn,
                        .fi-header .fi-ac { flex-wrap: wrap; max-width: 100%; justify-content: flex-end; }
                        [data-classification-review-section],
                        [data-curation-audit-section] {
                            grid-column: 1 / -1;
                            min-width: 0;
                            max-width: 100%;
                        }
                        [data-classification-review],
                        [data-curation-audit-review] {
                            min-width: 0;
                            width: 100%;
                            max-width: 100%;
                            overflow-wrap: break-word;
                        }
                        [data-classification-review] dt,
                        [data-curation-audit-review] dt {
                            display: block;
                            margin: 0;
                        }
                        [data-classification-review] dd,
                        [data-curation-audit-review] dd {
                            display: block;
                            margin: 0.25rem 0 0;
                        }
                        [data-classification-review] p,
                        [data-classification-review] li,
                        [data-classification-review] td,
                        [data-curation-audit-review] p,
                        [data-curation-audit-review] li,
                        [data-curation-audit-review] td {
                            overflow-wrap: break-word;
                            word-break: normal;
                        }
                        [data-proposal-vs-applied],
                        [data-strengths-concerns],
                        [data-curation-factor-breakdown] {
                            display: grid;
                            grid-template-columns: minmax(0, 1fr);
                            gap: 1rem;
                            min-width: 0;
                        }
                        @media (min-width: 1024px) {
                            [data-proposal-vs-applied],
                            [data-strengths-concerns],
                            [data-curation-factor-breakdown] {
                                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                            }
                        }
                        [data-review-layout] details > summary {
                            list-style-position: outside;
                        }
                        [data-curation-factor-breakdown] table {
                            border-collapse: collapse;
                        }
                        [data-curation-factor-breakdown] th,
                        [data-curation-factor-breakdown] td {
                            border: 1px solid rgb(229 231 235);
                        }
                        .dark [data-curation-factor-breakdown] th,
                        .dark [data-curation-factor-breakdown] td {
                            border-color: rgb(55 65 81);
                        }
                        [data-frontend-link] {
                            flex-shrink: 0;
                            white-space: nowrap;
                        }
                    </style>
                HTML,
            );
    }
}
