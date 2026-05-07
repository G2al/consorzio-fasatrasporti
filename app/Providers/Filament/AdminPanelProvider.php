<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\HtmlString;
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
            ->authGuard('admin')
            ->login()
            ->favicon(asset('images/favicon_consorzio.png'))
            ->brandLogo(asset('images/logo_consorzio_white_trimmed.png'))
            ->brandLogoHeight('5rem')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->navigationGroups([
                NavigationGroup::make('Utenti')
                    ->collapsible(),
                NavigationGroup::make('Documenti')
                    ->collapsible(),
                NavigationGroup::make('Richieste')
                    ->collapsible(),
                NavigationGroup::make('Impostazioni')
                    ->collapsed(),
            ])
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): HtmlString => new HtmlString(<<<'HTML'
                    <script>
                        (() => {
                            const badgeConfigs = [
                                {
                                    endpoint: '/admin/document-approvals/pending-count',
                                    targetPath: '/admin/document-approvals',
                                },
                                {
                                    endpoint: '/admin/deletion-requests/pending-count',
                                    targetPath: '/admin/deletion-requests',
                                },
                            ];

                            function navLinks(targetPath) {
                                return Array.from(document.querySelectorAll('a.fi-sidebar-item-btn[href]'))
                                    .filter((link) => {
                                        try {
                                            return new URL(link.href).pathname.replace(/\/$/, '') === targetPath;
                                        } catch (error) {
                                            return false;
                                        }
                                    });
                            }

                            function ensureBadge(link, badgeKey) {
                                let container = link.querySelector('[data-live-approval-badge]');

                                if (container) {
                                    return container;
                                }

                                const existingContainer = link.querySelector('.fi-sidebar-item-badge-ctn');

                                if (existingContainer) {
                                    existingContainer.dataset.liveApprovalBadge = 'true';
                                    return existingContainer;
                                }

                                container = document.createElement('span');
                                container.dataset.liveApprovalBadge = 'true';
                                container.dataset.liveBadgeKey = badgeKey;
                                container.className = 'fi-sidebar-item-badge-ctn';
                                container.innerHTML = '<span class="fi-badge fi-color-danger">0</span>';
                                link.appendChild(container);

                                return container;
                            }

                            function setBadge(config, count) {
                                const badgeKey = config.targetPath.replace(/[^a-z0-9]+/gi, '-');

                                navLinks(config.targetPath).forEach((link) => {
                                    const container = ensureBadge(link, badgeKey);
                                    const badge = container.querySelector('.fi-badge') || container;

                                    container.hidden = count <= 0;
                                    badge.textContent = String(count);
                                });
                            }

                            async function refreshBadge(config) {
                                try {
                                    const response = await fetch(config.endpoint, {
                                        headers: { Accept: 'application/json' },
                                        credentials: 'same-origin',
                                    });

                                    if (!response.ok) {
                                        return;
                                    }

                                    const data = await response.json();
                                    setBadge(config, Number(data.count || 0));
                                } catch (error) {
                                    // Silenzioso: il badge non deve disturbare il lavoro nel pannello.
                                }
                            }

                            function refreshAllBadges() {
                                badgeConfigs.forEach((config) => refreshBadge(config));
                            }

                            document.addEventListener('DOMContentLoaded', refreshAllBadges);
                            document.addEventListener('livewire:navigated', refreshAllBadges);
                            window.setInterval(refreshAllBadges, 10000);
                        })();
                    </script>
                HTML),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
